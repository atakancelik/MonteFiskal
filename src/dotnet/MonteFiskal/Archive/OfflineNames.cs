using System.Globalization;

namespace MonteFiskal.Archive;

/// <summary>File and archive names of the offline export (BR-O11).</summary>
public static class OfflineNames
{
    /// <summary>Maximum size of one export archive: 15 MB.</summary>
    public const long MaxZipBytes = 15L * 1024 * 1024;

    /// <summary><c>yyyyMMddHHmmss</c> of the local time as written in the request.</summary>
    public static string Stamp(DateTimeOffset localTime) => localTime.ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture);

    /// <summary>Parses a wire date-time (<c>2026-11-13T10:15:30+01:00</c>) keeping its offset.</summary>
    public static DateTimeOffset ParseWire(string issueDateTime) => DateTimeOffset.Parse(issueDateTime, CultureInfo.InvariantCulture, DateTimeStyles.None);

    public static string RequestFile(string issueDateTime, string tcr, string iic) => $"{Stamp(ParseWire(issueDateTime))}_{tcr}_{iic}_request.xml";

    public static string ResponseFile(string issueDateTime, string tcr, string iic) => $"{Stamp(ParseWire(issueDateTime))}_{tcr}_{iic}_response.xml";

    public static string RequestZip(DateTimeOffset exportedAtLocal, string tcr) => $"{Stamp(exportedAtLocal)}_{tcr}_request.zip";

    public static string ResponseZip(DateTimeOffset exportedAtLocal, string tcr) => $"{Stamp(exportedAtLocal)}_{tcr}_response.zip";
}

/// <summary>Durable copy of everything sent and received (BR-X1, BR-X2).</summary>
public interface IArchiveStore
{
    Task SaveAsync(Guid requestId, string kind, string content, CancellationToken cancellationToken);

    Task<IReadOnlyList<(string Kind, string Content)>> LoadAsync(Guid requestId, CancellationToken cancellationToken);
}

/// <summary>Archive that keeps nothing; the outbox still holds the last request and response.</summary>
public sealed class NullArchiveStore : IArchiveStore
{
    public static NullArchiveStore Instance { get; } = new();

    public Task SaveAsync(Guid requestId, string kind, string content, CancellationToken cancellationToken) => Task.CompletedTask;

    public Task<IReadOnlyList<(string Kind, string Content)>> LoadAsync(Guid requestId, CancellationToken cancellationToken) =>
        Task.FromResult<IReadOnlyList<(string, string)>>([]);
}
