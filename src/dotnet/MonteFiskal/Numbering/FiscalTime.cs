using System.Collections.Concurrent;
using System.Globalization;

namespace MonteFiskal.Numbering;

/// <summary>Date-time formatting of the wire (S-07, BR-I4, Q-23).</summary>
public static class FiscalTime
{
    private static readonly ConcurrentDictionary<string, TimeZoneInfo> Zones = new(StringComparer.Ordinal);

    public static TimeZoneInfo Zone(string id) => Zones.GetOrAdd(id, TimeZoneInfo.FindSystemTimeZoneById);

    /// <summary>The instant in the given zone, with fractional seconds truncated.</summary>
    public static DateTimeOffset Local(DateTimeOffset instant, string timeZone)
    {
        var truncated = new DateTimeOffset(instant.UtcTicks - (instant.UtcTicks % TimeSpan.TicksPerSecond), TimeSpan.Zero);
        return TimeZoneInfo.ConvertTime(truncated, Zone(timeZone));
    }

    /// <summary>
    /// Formats an instant: local time with offset (default) or UTC with <c>Z</c>; seconds precision.
    /// The same string is used for the XML, the IKOF and the QR code (BR-I4).
    /// </summary>
    public static string Format(DateTimeOffset instant, string timeZone, MonteFiskal.DateTimeFormat format = MonteFiskal.DateTimeFormat.LocalOffset)
    {
        if (format == MonteFiskal.DateTimeFormat.Utc)
        {
            var u = Local(instant, timeZone).ToUniversalTime();
            return u.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture);
        }
        return Local(instant, timeZone).ToString("yyyy-MM-dd'T'HH:mm:sszzz", CultureInfo.InvariantCulture);
    }

    /// <summary>Calendar year of the instant in local time (BR-N4).</summary>
    public static int LocalYear(DateTimeOffset instant, string timeZone) => Local(instant, timeZone).Year;

    /// <summary>Local calendar date of the instant.</summary>
    public static DateOnly LocalDate(DateTimeOffset instant, string timeZone) => DateOnly.FromDateTime(Local(instant, timeZone).DateTime);
}

/// <summary>Invoice number (BR-N1, BR-N2, BR-N4).</summary>
public static class InvoiceNumber
{
    /// <summary><c>BusinUnitCode/InvOrdNum/year/TCRCode</c>, the year taken from local time.</summary>
    public static string Format(string businessUnit, long ordinal, DateTimeOffset issuedAt, string tcr, string timeZone)
    {
        // BR-N2: the ordinal is a positive number.
        if (ordinal <= 0)
        {
            throw new ArgumentOutOfRangeException(nameof(ordinal), ordinal, "BR-N2: the ordinal must be at least 1.");
        }
        var year = FiscalTime.LocalYear(issuedAt, timeZone);
        return string.Create(CultureInfo.InvariantCulture, $"{businessUnit}/{ordinal}/{year}/{tcr}");
    }
}
