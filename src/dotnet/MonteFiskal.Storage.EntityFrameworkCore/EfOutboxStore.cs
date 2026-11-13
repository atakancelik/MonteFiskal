using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using MonteFiskal.Model;
using MonteFiskal.Outbox;

namespace MonteFiskal.Storage.EntityFrameworkCore;

/// <summary>Sequence of invoice ordinals per scope and year (BR-N3).</summary>
public sealed class SequenceRow
{
    public string Key { get; set; } = "";
    public long Value { get; set; }
    public long Version { get; set; }
}

/// <summary>A stored request. Query columns are copied out of the JSON document.</summary>
public sealed class RequestRow
{
    public Guid Id { get; set; }
    public string Operation { get; set; } = "";
    public string State { get; set; } = "";
    public string? Iic { get; set; }
    public string? InvoiceNumber { get; set; }
    public string Tcr { get; set; } = "";
    public DateTimeOffset IssuedAt { get; set; }
    public long IssuedAtTicks { get; set; }
    public string Data { get; set; } = "";
    public long Version { get; set; }
}

/// <summary>EF Core model of the outbox. Add it to an application context with <see cref="MonteFiskalModel.Configure"/> or use it directly.</summary>
public sealed class MonteFiskalDbContext(DbContextOptions<MonteFiskalDbContext> options) : DbContext(options)
{
    public DbSet<SequenceRow> Sequences => Set<SequenceRow>();
    public DbSet<RequestRow> Requests => Set<RequestRow>();

    protected override void OnModelCreating(ModelBuilder modelBuilder) => MonteFiskalModel.Configure(modelBuilder);
}

public static class MonteFiskalModel
{
    public static void Configure(ModelBuilder b)
    {
        ArgumentNullException.ThrowIfNull(b);
        b.Entity<SequenceRow>(e =>
        {
            e.ToTable("montefiskal_sequences");
            e.HasKey(x => x.Key);
            e.Property(x => x.Key).HasMaxLength(200);
            e.Property(x => x.Version).IsConcurrencyToken();
        });
        b.Entity<RequestRow>(e =>
        {
            e.ToTable("montefiskal_requests");
            e.HasKey(x => x.Id);
            e.Property(x => x.Operation).HasMaxLength(40);
            e.Property(x => x.State).HasMaxLength(20);
            e.Property(x => x.Iic).HasMaxLength(32);
            e.Property(x => x.InvoiceNumber).HasMaxLength(100);
            e.Property(x => x.Tcr).HasMaxLength(20);
            e.Property(x => x.Version).IsConcurrencyToken();
            e.Ignore(x => x.IssuedAt);
            e.HasIndex(x => x.Iic).IsUnique();
            e.HasIndex(x => new { x.State, x.IssuedAtTicks });
            e.HasIndex(x => x.IssuedAtTicks);
        });
    }
}

/// <summary>
/// Durable <see cref="IOutboxStore"/>. <see cref="IssueAsync"/> increments the sequence row and inserts the request in
/// one transaction; a concurrent issuer loses the optimistic-concurrency race and retries with the next ordinal (BR-N5).
/// </summary>
public sealed class EfOutboxStore(Func<MonteFiskalDbContext> contextFactory) : IOutboxStore
{
    private const int MaxRetries = 10;

    public async Task<StoredRequest> IssueAsync(string sequenceKey, Func<long, StoredRequest> build, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(build);
        for (var attempt = 0; ; attempt++)
        {
            await using var db = contextFactory();
            await using var tx = await db.Database.BeginTransactionAsync(cancellationToken).ConfigureAwait(false);
            var seq = await db.Sequences.SingleOrDefaultAsync(s => s.Key == sequenceKey, cancellationToken).ConfigureAwait(false);
            if (seq is null)
            {
                seq = new SequenceRow { Key = sequenceKey, Value = 0, Version = 0 };
                db.Sequences.Add(seq);
            }
            var next = seq.Value + 1;
            var request = build(next) with { SequenceKey = sequenceKey, Ordinal = next };
            seq.Value = next;
            seq.Version++;
            db.Requests.Add(ToRow(request));
            try
            {
                await db.SaveChangesAsync(cancellationToken).ConfigureAwait(false);
                await tx.CommitAsync(cancellationToken).ConfigureAwait(false);
                return request;
            }
            catch (DbUpdateException) when (attempt < MaxRetries)
            {
                await tx.RollbackAsync(cancellationToken).ConfigureAwait(false);
            }
        }
    }

    public async Task<StoredRequest> AddAsync(StoredRequest request, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(request);
        await using var db = contextFactory();
        db.Requests.Add(ToRow(request));
        await db.SaveChangesAsync(cancellationToken).ConfigureAwait(false);
        return request;
    }

    public async Task UpdateAsync(StoredRequest request, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(request);
        await using var db = contextFactory();
        var row = await db.Requests.SingleAsync(r => r.Id == request.Id, cancellationToken).ConfigureAwait(false);
        if (row.Version != request.Version)
        {
            throw new DbUpdateConcurrencyException("The request was changed by someone else.");
        }
        var updated = ToRow(request with { Version = request.Version + 1 });
        db.Entry(row).CurrentValues.SetValues(updated);
        await db.SaveChangesAsync(cancellationToken).ConfigureAwait(false);
    }

    public async Task<StoredRequest?> GetAsync(Guid id, CancellationToken cancellationToken)
    {
        await using var db = contextFactory();
        var row = await db.Requests.AsNoTracking().SingleOrDefaultAsync(r => r.Id == id, cancellationToken).ConfigureAwait(false);
        return row is null ? null : FromRow(row);
    }

    public async Task<StoredRequest?> FindByIicAsync(string iic, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(iic);
        var upper = iic.ToUpperInvariant();
        await using var db = contextFactory();
        var row = await db.Requests.AsNoTracking().SingleOrDefaultAsync(r => r.Iic == upper, cancellationToken).ConfigureAwait(false);
        return row is null ? null : FromRow(row);
    }

    public async Task<IReadOnlyList<StoredRequest>> PendingAsync(int max, CancellationToken cancellationToken)
    {
        string[] states = [nameof(InvoiceState.Issued), nameof(InvoiceState.Pending), nameof(InvoiceState.Overdue)];
        await using var db = contextFactory();
        var rows = await db.Requests.AsNoTracking().Where(r => states.Contains(r.State)).OrderBy(r => r.IssuedAtTicks).Take(max).ToListAsync(cancellationToken).ConfigureAwait(false);
        return rows.Select(FromRow).ToList();
    }

    public async Task<IReadOnlyList<StoredRequest>> ListAsync(DateTimeOffset from, DateTimeOffset to, CancellationToken cancellationToken)
    {
        long a = from.UtcTicks, b = to.UtcTicks;
        await using var db = contextFactory();
        var rows = await db.Requests.AsNoTracking().Where(r => r.IssuedAtTicks >= a && r.IssuedAtTicks <= b).OrderBy(r => r.IssuedAtTicks).ToListAsync(cancellationToken).ConfigureAwait(false);
        return rows.Select(FromRow).ToList();
    }

    private static RequestRow ToRow(StoredRequest r) => new()
    {
        Id = r.Id,
        Operation = r.Operation.ToString(),
        State = r.State.ToString(),
        Iic = r.Iic?.ToUpperInvariant(),
        InvoiceNumber = r.InvoiceNumber,
        Tcr = r.Tcr,
        IssuedAtTicks = r.IssuedAt.UtcTicks,
        Data = JsonSerializer.Serialize(r, MonteFiskalJson.Options),
        Version = r.Version,
    };

    private static StoredRequest FromRow(RequestRow row) => JsonSerializer.Deserialize<StoredRequest>(row.Data, MonteFiskalJson.Options)! with { Version = row.Version };
}
