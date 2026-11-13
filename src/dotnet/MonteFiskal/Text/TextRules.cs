using System.Text;

namespace MonteFiskal.Text;

/// <summary>Character checks and optional transliteration (BR-T8, Q-07).</summary>
public static class TextRules
{
    /// <summary>BR-T8: allowed codes are 9, 10, 13 and 32 to 126. Other control characters are never allowed.</summary>
    public static bool HasControlCharacter(string? s) =>
        s is not null && s.Any(c => (c < 32 && c != '\t' && c != '\n' && c != '\r') || c == 127 || c is >= '\u0080' and <= '\u009F');

    /// <summary>True when the text contains characters outside printable ASCII (BR-T8, UNVERIFIED enforcement).</summary>
    public static bool HasNonAscii(string? s) => s is not null && s.Any(c => c > 126);

    private static readonly Dictionary<char, string> Map = new()
    {
        ['č'] = "c", ['ć'] = "c", ['š'] = "s", ['ž'] = "z", ['đ'] = "dj", ['Č'] = "C", ['Ć'] = "C", ['Š'] = "S", ['Ž'] = "Z", ['Đ'] = "Dj",
        ['ś'] = "s", ['Ś'] = "S", ['ź'] = "z", ['Ź'] = "Z", ['ä'] = "a", ['ö'] = "o", ['ü'] = "u", ['Ä'] = "A", ['Ö'] = "O", ['Ü'] = "U",
        ['ß'] = "ss", ['ç'] = "c", ['Ç'] = "C", ['ğ'] = "g", ['Ğ'] = "G", ['ı'] = "i", ['İ'] = "I", ['ş'] = "s", ['Ş'] = "S",
        ['€'] = "EUR", ['–'] = "-", ['—'] = "-", ['„'] = "\"", ['“'] = "\"", ['”'] = "\"", ['‘'] = "'", ['’'] = "'",
    };

    /// <summary>Montenegrin Latin to ASCII (č → c, đ → dj, ...); other letters lose their diacritics; the rest becomes '?'.</summary>
    public static string Transliterate(string s)
    {
        ArgumentNullException.ThrowIfNull(s);
        var sb = new StringBuilder(s.Length);
        foreach (var c in s)
        {
            if (c <= 126)
            {
                sb.Append(c);
            }
            else if (Map.TryGetValue(c, out var r))
            {
                sb.Append(r);
            }
            else
            {
                var decomposed = c.ToString().Normalize(NormalizationForm.FormD);
                var basic = decomposed.Where(x => x <= 126).ToArray();
                sb.Append(basic.Length > 0 ? new string(basic) : "?");
            }
        }
        return sb.ToString();
    }
}
