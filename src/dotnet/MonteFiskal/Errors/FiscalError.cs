using System.Reflection;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace MonteFiskal.Errors;

/// <summary>Class of a CIS fault code (error-codes.md §3).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<FiscalErrorClass>))]
public enum FiscalErrorClass
{
    [JsonStringEnumMemberName("unknown")] Unknown,
    [JsonStringEnumMemberName("malformed")] Malformed,
    [JsonStringEnumMemberName("schema")] Schema,
    [JsonStringEnumMemberName("signature")] Signature,
    [JsonStringEnumMemberName("certificate")] Certificate,
    [JsonStringEnumMemberName("registration")] Registration,
    [JsonStringEnumMemberName("business")] Business,
    [JsonStringEnumMemberName("tcr")] Tcr,
    [JsonStringEnumMemberName("cashDeposit")] CashDeposit,
    [JsonStringEnumMemberName("clock")] Clock,
    [JsonStringEnumMemberName("server")] Server,
}

/// <summary>A fault returned by CIS (a value, not an exception; architecture §2.4).</summary>
public sealed record FiscalError(
    int? Code,
    FiscalErrorClass Class,
    bool Retryable,
    bool Blocking,
    string FaultCode,
    string FaultString,
    Guid? RequestUuid,
    Guid? ResponseUuid,
    string? Hint = null)
{
    public override string ToString() => $"{Code?.ToString(System.Globalization.CultureInfo.InvariantCulture) ?? "-"} {Class}: {FaultString}";
}

/// <summary>One entry of <c>data/error-codes.json</c>.</summary>
public sealed record ErrorCodeEntry
{
    public int? Code { get; init; }
    public int[]? Range { get; init; }
    [JsonPropertyName("class")] public FiscalErrorClass Class { get; init; }
    public bool Retryable { get; init; }
    public bool RetryAfterFix { get; init; }
    public bool Blocking { get; init; }
    public string Origin { get; init; } = "";
    public string TextMe { get; init; } = "";
    public string TextEn { get; init; } = "";
    public string Cause { get; init; } = "";
    public string Action { get; init; } = "";
}

/// <summary>The data-driven fault catalogue shared with the PHP core.</summary>
public static class ErrorCatalog
{
    private sealed record Doc(IReadOnlyList<ErrorCodeEntry> Codes);

    private static readonly Lazy<IReadOnlyList<ErrorCodeEntry>> Entries = new(() =>
    {
        using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream("MonteFiskal.Data.error-codes.json")!;
        var doc = JsonSerializer.Deserialize<Doc>(s, new JsonSerializerOptions(JsonSerializerDefaults.Web))!;
        return doc.Codes;
    });

    public static IReadOnlyList<ErrorCodeEntry> All => Entries.Value;

    public static ErrorCodeEntry? Find(int code) =>
        All.FirstOrDefault(e => e.Code == code) ?? All.FirstOrDefault(e => e.Range is [var lo, var hi] && code >= lo && code <= hi);

    /// <summary>Classifies a fault. Unknown codes 59 to 899 are permanent; a SERVER fault code is retryable.</summary>
    public static FiscalError Classify(int? code, string faultCode, string faultString, Guid? requestUuid, Guid? responseUuid)
    {
        var entry = code is { } c ? Find(c) : null;
        var server = faultCode.EndsWith("SERVER", StringComparison.OrdinalIgnoreCase);
        if (entry is not null)
        {
            return new FiscalError(code, entry.Class, entry.Retryable || server, entry.Blocking, faultCode, faultString, requestUuid, responseUuid);
        }
        return new FiscalError(code, server ? FiscalErrorClass.Server : FiscalErrorClass.Unknown, server, false, faultCode, faultString, requestUuid, responseUuid);
    }
}
