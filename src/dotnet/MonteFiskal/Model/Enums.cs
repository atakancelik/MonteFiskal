using System.Collections.Concurrent;
using System.Reflection;
using System.Text.Json.Serialization;

namespace MonteFiskal.Model;

/// <summary>Invoice type (<c>InvType</c>, BR-K6).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<InvoiceKind>))]
public enum InvoiceKind
{
    [JsonStringEnumMemberName("INVOICE")] Invoice,
    [JsonStringEnumMemberName("CORRECTIVE")] Corrective,
    [JsonStringEnumMemberName("SUMMARY")] Summary,
    [JsonStringEnumMemberName("PERIODICAL")] Periodical,
    [JsonStringEnumMemberName("ADVANCE")] Advance,
    [JsonStringEnumMemberName("CREDIT_NOTE")] CreditNote,
}

/// <summary>Cash or non-cash invoice (<c>TypeOfInv</c>, BR-K1, BR-K3).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<TypeOfInvoice>))]
public enum TypeOfInvoice
{
    [JsonStringEnumMemberName("CASH")] Cash,
    [JsonStringEnumMemberName("NONCASH")] NonCash,
}

/// <summary>Payment method (<c>PayMethod/@Type</c>, BR-K2).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<PaymentMethod>))]
public enum PaymentMethod
{
    [JsonStringEnumMemberName("BANKNOTE")] Banknote,
    [JsonStringEnumMemberName("CARD")] Card,
    [JsonStringEnumMemberName("BUSINESSCARD")] BusinessCard,
    [JsonStringEnumMemberName("SVOUCHER")] SingleVoucher,
    [JsonStringEnumMemberName("COMPANY")] Company,
    [JsonStringEnumMemberName("ORDER")] Order,
    [JsonStringEnumMemberName("ADVANCE")] Advance,
    [JsonStringEnumMemberName("ACCOUNT")] Account,
    [JsonStringEnumMemberName("FACTORING")] Factoring,
    [JsonStringEnumMemberName("OTHER")] Other,
    [JsonStringEnumMemberName("OTHER-CASH")] OtherCash,
}

/// <summary>Self-issuing kind (<c>TypeOfSelfIss</c>, BR-K10).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<SelfIssuingKind>))]
public enum SelfIssuingKind
{
    [JsonStringEnumMemberName("AGREEMENT")] Agreement,
    [JsonStringEnumMemberName("DOMESTIC")] Domestic,
    [JsonStringEnumMemberName("ABROAD")] Abroad,
    [JsonStringEnumMemberName("SELF")] Self,
    [JsonStringEnumMemberName("OTHER")] Other,
}

/// <summary>Identification type of a party (<c>IDType</c>).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<IdType>))]
public enum IdType
{
    [JsonStringEnumMemberName("TIN")] Tin,
    [JsonStringEnumMemberName("ID")] PersonalId,
    [JsonStringEnumMemberName("PASS")] Passport,
    [JsonStringEnumMemberName("VAT")] Vat,
    [JsonStringEnumMemberName("TAX")] Tax,
    [JsonStringEnumMemberName("SOC")] Social,
}

/// <summary>Fee type (<c>Fee/@Type</c>).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<FeeType>))]
public enum FeeType
{
    [JsonStringEnumMemberName("PACK")] Pack,
    [JsonStringEnumMemberName("COMMISSION")] Commission,
    [JsonStringEnumMemberName("OTHER")] Other,
}

/// <summary>Corrective invoice type (<c>CorrectiveInv/@Type</c>, BR-C4).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<CorrectionType>))]
public enum CorrectionType
{
    [JsonStringEnumMemberName("CORRECTIVE")] Corrective,
    [JsonStringEnumMemberName("ERROR_CORRECTIVE")] ErrorCorrective,
}

/// <summary>Reason for a late delivery (<c>SubseqDelivType</c>, BR-O1 to BR-O6).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<SubsequentDelivery>))]
public enum SubsequentDelivery
{
    [JsonStringEnumMemberName("NOINTERNET")] NoInternet,
    [JsonStringEnumMemberName("BOUNDBOOK")] BoundBook,
    [JsonStringEnumMemberName("SERVICE")] Service,
    [JsonStringEnumMemberName("TECHNICALERROR")] TechnicalError,
    [JsonStringEnumMemberName("BUSINESSNEEDS")] BusinessNeeds,
}

/// <summary>Cash deposit operation (BR-D1, BR-D2).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<CashDepositOperation>))]
public enum CashDepositOperation
{
    [JsonStringEnumMemberName("INITIAL")] Initial,
    [JsonStringEnumMemberName("WITHDRAW")] Withdraw,
}

/// <summary>TCR type (BR-R2).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<TcrType>))]
public enum TcrType
{
    [JsonStringEnumMemberName("REGULAR")] Regular,
    [JsonStringEnumMemberName("VENDING")] Vending,
}

/// <summary>Target environment (BR-T1).</summary>
[JsonConverter(typeof(JsonStringEnumConverter<FiscalEnvironment>))]
public enum FiscalEnvironment
{
    [JsonStringEnumMemberName("test")] Test,
    [JsonStringEnumMemberName("production")] Production,
}

/// <summary>State of a stored request (architecture §2.5).</summary>
public enum InvoiceState
{
    Issued,
    Pending,
    Overdue,
    Fiscalized,
    Rejected,
}

/// <summary>Maps enumerations to their wire names (the <see cref="JsonStringEnumMemberNameAttribute"/> values).</summary>
public static class WireName
{
    private static readonly ConcurrentDictionary<Type, (Dictionary<string, object> Parse, Dictionary<object, string> Format)> Cache = new();

    public static string Of<T>(T value) where T : struct, Enum => Table(typeof(T)).Format[value];

    public static T Parse<T>(string wire) where T : struct, Enum =>
        Table(typeof(T)).Parse.TryGetValue(wire, out var v) ? (T)v : throw new FormatException($"'{wire}' is not a valid {typeof(T).Name}.");

    public static bool TryParse<T>(string? wire, out T value) where T : struct, Enum
    {
        if (wire is not null && Table(typeof(T)).Parse.TryGetValue(wire, out var v))
        {
            value = (T)v;
            return true;
        }
        value = default;
        return false;
    }

    private static (Dictionary<string, object> Parse, Dictionary<object, string> Format) Table(Type t) => Cache.GetOrAdd(t, static type =>
    {
        var parse = new Dictionary<string, object>(StringComparer.Ordinal);
        var format = new Dictionary<object, string>();
        foreach (var f in type.GetFields(BindingFlags.Public | BindingFlags.Static))
        {
            var name = f.GetCustomAttribute<JsonStringEnumMemberNameAttribute>()?.Name ?? f.Name;
            var value = f.GetValue(null)!;
            parse[name] = value;
            format[value] = name;
        }
        return (parse, format);
    });
}
