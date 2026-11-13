using System.IO.Compression;
using System.Text;
using MonteFiskal.Archive;
using MonteFiskal.Model;
using MonteFiskal.Numbering;
using MonteFiskal.Outbox;

namespace MonteFiskal.Storage.FileSystem;

/// <summary>Archive of every request and response on disk (BR-X1, BR-X2). Files are never overwritten.</summary>
public sealed class FileArchiveStore : IArchiveStore
{
    private readonly string _root;

    public FileArchiveStore(string root)
    {
        ArgumentException.ThrowIfNullOrEmpty(root);
        _root = root;
        Directory.CreateDirectory(root);
    }

    public async Task SaveAsync(Guid requestId, string kind, string content, CancellationToken cancellationToken)
    {
        var dir = Path.Combine(_root, requestId.ToString("N")[..2], requestId.ToString("N"));
        Directory.CreateDirectory(dir);
        var name = $"{DateTimeOffset.UtcNow:yyyyMMddHHmmssfffffff}_{Sanitize(kind)}.xml";
        await File.WriteAllTextAsync(Path.Combine(dir, name), content, new UTF8Encoding(false), cancellationToken).ConfigureAwait(false);
    }

    public async Task<IReadOnlyList<(string Kind, string Content)>> LoadAsync(Guid requestId, CancellationToken cancellationToken)
    {
        var dir = Path.Combine(_root, requestId.ToString("N")[..2], requestId.ToString("N"));
        if (!Directory.Exists(dir))
        {
            return [];
        }
        var result = new List<(string, string)>();
        foreach (var f in Directory.GetFiles(dir, "*.xml").Order(StringComparer.Ordinal))
        {
            var kind = Path.GetFileNameWithoutExtension(f).Split('_', 2)[1];
            result.Add((kind, await File.ReadAllTextAsync(f, cancellationToken).ConfigureAwait(false)));
        }
        return result;
    }

    private static string Sanitize(string s) => new(s.Where(char.IsAsciiLetterOrDigit).ToArray());
}

/// <summary>
/// Offline export and import (BR-O11): one <c>&lt;yyyyMMddHHmmss&gt;_&lt;TCR&gt;_&lt;IIC&gt;_request.xml</c> per invoice in a
/// <c>&lt;yyyyMMddHHmmss&gt;_&lt;TCR&gt;_request.zip</c> of at most 15 MB; responses come back as <c>_response.xml</c> files.
/// </summary>
public static class OfflineExchange
{
    /// <summary>Writes the zip archives and returns their paths. Each further archive takes the next second in its name.</summary>
    public static IReadOnlyList<string> Export(IEnumerable<StoredRequest> invoices, Func<StoredRequest, string> signedRequest, string tcr, DateTimeOffset exportedAt, string timeZone, string directory)
    {
        ArgumentNullException.ThrowIfNull(invoices);
        ArgumentNullException.ThrowIfNull(signedRequest);
        Directory.CreateDirectory(directory);
        var paths = new List<string>();
        var stamp = FiscalTime.Local(exportedAt, timeZone);
        var pending = invoices.Where(i => i.Operation == Transport.FiscalOperation.RegisterInvoice && i.State != InvoiceState.Fiscalized).OrderBy(i => i.IssuedAt).ToList();
        var index = 0;
        while (index < pending.Count)
        {
            var path = Path.Combine(directory, OfflineNames.RequestZip(stamp, tcr));
            stamp = stamp.AddSeconds(1);
            using (var fs = File.Create(path))
            using (var zip = new ZipArchive(fs, ZipArchiveMode.Create))
            {
                long size = 22;
                while (index < pending.Count)
                {
                    var inv = pending[index];
                    var xml = Encoding.UTF8.GetBytes(signedRequest(inv));
                    var name = OfflineNames.RequestFile(inv.IssueDateTime!, tcr, inv.Iic!);
                    var estimate = xml.Length + (2 * name.Length) + 100;
                    if (size + estimate > OfflineNames.MaxZipBytes && size > 22)
                    {
                        break;
                    }
                    var entry = zip.CreateEntry(name, CompressionLevel.Optimal);
                    using (var es = entry.Open())
                    {
                        es.Write(xml);
                    }
                    size += estimate;
                    index++;
                }
            }
            paths.Add(path);
        }
        return paths;
    }

    /// <summary>Reads a response archive: IKOF from the file name and the response XML.</summary>
    public static IReadOnlyList<(string Iic, string ResponseXml)> ReadResponses(string zipPath)
    {
        using var zip = ZipFile.OpenRead(zipPath);
        var result = new List<(string, string)>();
        foreach (var e in zip.Entries.Where(e => e.Name.EndsWith("_response.xml", StringComparison.Ordinal)))
        {
            var parts = e.Name.Split('_');
            if (parts.Length < 4)
            {
                continue;
            }
            using var r = new StreamReader(e.Open(), Encoding.UTF8);
            result.Add((parts[2], r.ReadToEnd()));
        }
        return result;
    }
}
