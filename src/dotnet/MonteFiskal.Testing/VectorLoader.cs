using System.Text.Json;

namespace MonteFiskal.Testing;

/// <summary>One shared test vector (test-vectors/README.md).</summary>
public sealed record TestVector(string Id, string Kind, string Title, string Status, IReadOnlyList<string> Rules, JsonElement Input, JsonElement Expected)
{
    public override string ToString() => Id;

    /// <summary>Deserializes the input (or a property of it) with the library's JSON settings.</summary>
    public T InputAs<T>(string? property = null) =>
        (property is null ? Input : Input.GetProperty(property)).Deserialize<T>(MonteFiskalJson.Options)!;
}

/// <summary>Loads the vectors of <c>test-vectors/</c>.</summary>
public static class VectorLoader
{
    /// <summary>The vector directory: <c>MONTEFISKAL_VECTORS</c>, or the nearest <c>test-vectors</c> above the base directory.</summary>
    public static string Directory
    {
        get
        {
            var env = Environment.GetEnvironmentVariable("MONTEFISKAL_VECTORS");
            if (!string.IsNullOrEmpty(env))
            {
                return env;
            }
            for (var d = new DirectoryInfo(AppContext.BaseDirectory); d is not null; d = d.Parent)
            {
                var candidate = Path.Combine(d.FullName, "test-vectors");
                if (File.Exists(Path.Combine(candidate, "README.md")))
                {
                    return candidate;
                }
            }
            throw new DirectoryNotFoundException("test-vectors not found; set MONTEFISKAL_VECTORS.");
        }
    }

    /// <summary>Repository root (the parent of <see cref="Directory"/>).</summary>
    public static string RepositoryRoot => System.IO.Directory.GetParent(Directory)!.FullName;

    public static IReadOnlyList<TestVector> Load(string kind)
    {
        var dir = Path.Combine(Directory, kind);
        return System.IO.Directory.GetFiles(dir, "*.json").Order(StringComparer.Ordinal).Select(Read).ToList();
    }

    public static TestVector Get(string id)
    {
        var kind = id[..id.IndexOf('-', StringComparison.Ordinal)];
        return Read(Path.Combine(Directory, kind, id + ".json"));
    }

    public static IEnumerable<TestVector> All() =>
        System.IO.Directory.GetDirectories(Directory).Where(d => Path.GetFileName(d) != "schema").Order(StringComparer.Ordinal).SelectMany(d => Load(Path.GetFileName(d)));

    private static TestVector Read(string path)
    {
        using var doc = JsonDocument.Parse(File.ReadAllText(path));
        var r = doc.RootElement.Clone();
        var rules = r.TryGetProperty("rules", out var rs) ? rs.EnumerateArray().Select(x => x.GetString()!).ToList() : [];
        return new TestVector(r.GetProperty("id").GetString()!, r.GetProperty("kind").GetString()!, r.GetProperty("title").GetString()!,
            r.GetProperty("status").GetString()!, rules, r.GetProperty("input"), r.GetProperty("expected"));
    }
}
