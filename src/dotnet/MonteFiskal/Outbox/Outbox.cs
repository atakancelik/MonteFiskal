using System.Collections.Concurrent;
using MonteFiskal.Errors;
using MonteFiskal.Model;
using MonteFiskal.Transport;
using MonteFiskal.Wire;

namespace MonteFiskal.Outbox;

/// <summary>One send attempt (architecture §2.5).</summary>
public sealed record Attempt(
    DateTimeOffset At,
    Guid HeaderUuid,
    ExchangeOutcome Outcome,
    int? ErrorCode,
    string? Problem,
    Guid? ResponseUuid);

/// <summary>
/// A stored request: the unsigned body element exactly as issued (BR-O7), its state, and every attempt.
/// Resending rebuilds only the header and the signature.
/// </summary>
public sealed record StoredRequest
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public FiscalOperation Operation { get; init; }
    public InvoiceState State { get; init; } = InvoiceState.Issued;
    public string SchemaRevision { get; init; } = Wire.SchemaSet.Revision;

    /// <summary>The unsigned body element (<c>Invoice</c>, <c>TCR</c> or <c>CashDeposit</c>) in canonical form.</summary>
    public required string Body { get; init; }

    public string IssuerTin { get; init; } = "";
    public string BusinessUnit { get; init; } = "";
    public string Tcr { get; init; } = "";
    public string? SequenceKey { get; init; }
    public long? Ordinal { get; init; }
    public string? InvoiceNumber { get; init; }
    public string? Iic { get; init; }
    public string? IicSignature { get; init; }
    public string? IssueDateTime { get; init; }
    public DateTimeOffset IssuedAt { get; init; }
    public InvoiceKind? Kind { get; init; }
    public TypeOfInvoice? TypeOfInv { get; init; }
    public string? Total { get; init; }
    public string? VerificationUrl { get; init; }

    /// <summary>For a corrective invoice: IKOF of the original, which must be fiscalized first (BR-C3).</summary>
    public string? DependsOnIic { get; init; }

    /// <summary>The draft as JSON, for corrections and reports.</summary>
    public string? DraftJson { get; init; }

    /// <summary>JIKR (FIC), TCR code or FCDC.</summary>
    public string? Result { get; init; }
    public DateTimeOffset? ResultAt { get; init; }
    public SubsequentDelivery? SubsequentDelivery { get; init; }
    public DateTimeOffset? NextAttemptAt { get; init; }
    public FiscalError? LastError { get; init; }
    public string? LastSignedRequest { get; init; }
    public string? LastResponse { get; init; }
    public IReadOnlyList<Attempt> Attempts { get; init; } = [];

    /// <summary>Concurrency token for durable stores.</summary>
    public long Version { get; init; }
}

/// <summary>
/// Durable storage of requests. <see cref="IssueAsync"/> allocates the next ordinal of a sequence and stores the
/// request in one transaction, before any network call (BR-N5): no gaps, no duplicates.
/// </summary>
public interface IOutboxStore
{
    /// <summary>Allocates the next ordinal of <paramref name="sequenceKey"/>, builds the request with it and stores both atomically.
    /// If <paramref name="build"/> throws, the ordinal is not consumed.</summary>
    Task<StoredRequest> IssueAsync(string sequenceKey, Func<long, StoredRequest> build, CancellationToken cancellationToken);

    /// <summary>Stores a request that has no ordinal (TCR registration, cash deposit).</summary>
    Task<StoredRequest> AddAsync(StoredRequest request, CancellationToken cancellationToken);

    Task UpdateAsync(StoredRequest request, CancellationToken cancellationToken);

    Task<StoredRequest?> GetAsync(Guid id, CancellationToken cancellationToken);

    Task<StoredRequest?> FindByIicAsync(string iic, CancellationToken cancellationToken);

    /// <summary>Requests in state Issued, Pending or Overdue, oldest first.</summary>
    Task<IReadOnlyList<StoredRequest>> PendingAsync(int max, CancellationToken cancellationToken);

    /// <summary>All requests issued in the interval, for reports and exports.</summary>
    Task<IReadOnlyList<StoredRequest>> ListAsync(DateTimeOffset from, DateTimeOffset to, CancellationToken cancellationToken);
}

/// <summary>In-memory store for tests and samples. Not durable.</summary>
public sealed class InMemoryOutboxStore : IOutboxStore
{
    private readonly ConcurrentDictionary<Guid, StoredRequest> _items = new();
    private readonly Dictionary<string, long> _sequences = new(StringComparer.Ordinal);
    private readonly Lock _lock = new();

    public Task<StoredRequest> IssueAsync(string sequenceKey, Func<long, StoredRequest> build, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(build);
        lock (_lock)
        {
            var next = _sequences.GetValueOrDefault(sequenceKey) + 1;
            var request = build(next) with { SequenceKey = sequenceKey, Ordinal = next };
            _items[request.Id] = request;
            _sequences[sequenceKey] = next;
            return Task.FromResult(request);
        }
    }

    public Task<StoredRequest> AddAsync(StoredRequest request, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(request);
        _items[request.Id] = request;
        return Task.FromResult(request);
    }

    public Task UpdateAsync(StoredRequest request, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(request);
        lock (_lock)
        {
            if (_items.TryGetValue(request.Id, out var existing) && existing.Version != request.Version)
            {
                throw new InvalidOperationException("The request was changed by someone else.");
            }
            _items[request.Id] = request with { Version = request.Version + 1 };
        }
        return Task.CompletedTask;
    }

    public Task<StoredRequest?> GetAsync(Guid id, CancellationToken cancellationToken) => Task.FromResult(_items.GetValueOrDefault(id));

    public Task<StoredRequest?> FindByIicAsync(string iic, CancellationToken cancellationToken) =>
        Task.FromResult(_items.Values.FirstOrDefault(r => string.Equals(r.Iic, iic, StringComparison.OrdinalIgnoreCase)));

    public Task<IReadOnlyList<StoredRequest>> PendingAsync(int max, CancellationToken cancellationToken) =>
        Task.FromResult<IReadOnlyList<StoredRequest>>(_items.Values
            .Where(r => r.State is InvoiceState.Issued or InvoiceState.Pending or InvoiceState.Overdue)
            .OrderBy(r => r.IssuedAt).Take(max).ToList());

    public Task<IReadOnlyList<StoredRequest>> ListAsync(DateTimeOffset from, DateTimeOffset to, CancellationToken cancellationToken) =>
        Task.FromResult<IReadOnlyList<StoredRequest>>(_items.Values.Where(r => r.IssuedAt >= from && r.IssuedAt <= to).OrderBy(r => r.IssuedAt).ToList());
}
