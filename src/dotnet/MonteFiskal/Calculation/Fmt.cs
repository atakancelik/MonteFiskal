using System.Globalization;

namespace MonteFiskal.Calculation;

/// <summary>Wire formatting of numbers (BR-A1). Rounding is half away from zero (BR-A5, Q-14 default).</summary>
public static class Fmt
{
    /// <summary>Rounds half away from zero to <paramref name="decimals"/> places.</summary>
    public static decimal Round(decimal value, int decimals) => Math.Round(value, decimals, MidpointRounding.AwayFromZero);

    /// <summary>Formats with exactly <paramref name="decimals"/> places; negative zero becomes zero.</summary>
    public static string Fixed(decimal value, int decimals)
    {
        var r = Round(value, decimals);
        if (r == 0m)
        {
            r = 0m;
        }
        return r.ToString("F" + decimals.ToString(CultureInfo.InvariantCulture), CultureInfo.InvariantCulture);
    }

    /// <summary>Totals and rates: two decimals.</summary>
    public static string Money(decimal value) => Fixed(value, 2);

    /// <summary>Quantity in the shortest form, not rounded (the schema allows at most three decimals).</summary>
    public static string Quantity(decimal value)
    {
        if (value == 0m)
        {
            return "0";
        }
        var s = value.ToString("0.############################", CultureInfo.InvariantCulture);
        return s == "-0" ? "0" : s;
    }

    /// <summary>A decimal in its shortest form (exchange rates).</summary>
    public static string Plain(decimal value) => Quantity(value);
}
