using System.Text;

namespace MonteFiskal.Rendering;

/// <summary>
/// QR code encoder following ISO/IEC 18004: byte mode, error correction level M (BR-Q5), versions 1 to 40,
/// mask chosen by the penalty rules of §7.8.3. Written for this project; see the shared <c>qr</c> vectors.
/// </summary>
public sealed class QrCode
{
    // Error correction codewords per block and number of blocks, level M, index = version.
    private static readonly int[] EccPerBlock = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private static readonly int[] Blocks = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    private readonly bool[,] _modules;
    private readonly bool[,] _function;

    private QrCode(int version)
    {
        Version = version;
        Size = (version * 4) + 17;
        _modules = new bool[Size, Size];
        _function = new bool[Size, Size];
    }

    public int Version { get; }
    public int Size { get; }
    public int Mask { get; private set; }

    /// <summary>True when the module at column <paramref name="x"/>, row <paramref name="y"/> is dark.</summary>
    public bool this[int x, int y] => _modules[y, x];

    /// <summary>Encodes UTF-8 text in byte mode at level M with the smallest version that fits.</summary>
    public static QrCode Encode(string text)
    {
        ArgumentNullException.ThrowIfNull(text);
        return Encode(Encoding.UTF8.GetBytes(text));
    }

    public static QrCode Encode(byte[] data)
    {
        ArgumentNullException.ThrowIfNull(data);
        int version = 1;
        for (; version <= 40; version++)
        {
            if (4 + CountBits(version) + (data.Length * 8) <= DataCodewords(version) * 8)
            {
                break;
            }
        }
        if (version > 40)
        {
            throw new ArgumentException("Data too long for a QR code at level M.", nameof(data));
        }
        var bits = new List<bool>();
        Append(bits, 0b0100, 4);
        Append(bits, data.Length, CountBits(version));
        foreach (var b in data)
        {
            Append(bits, b, 8);
        }
        var capacity = DataCodewords(version) * 8;
        Append(bits, 0, Math.Min(4, capacity - bits.Count));
        Append(bits, 0, (8 - (bits.Count % 8)) % 8);
        for (var pad = 0xEC; bits.Count < capacity; pad ^= 0xEC ^ 0x11)
        {
            Append(bits, pad, 8);
        }
        var codewords = new byte[bits.Count / 8];
        for (var i = 0; i < bits.Count; i++)
        {
            if (bits[i])
            {
                codewords[i >> 3] |= (byte)(1 << (7 - (i & 7)));
            }
        }

        var qr = new QrCode(version);
        qr.DrawFunctionPatterns();
        qr.DrawCodewords(AddEccAndInterleave(codewords, version));
        var best = -1;
        var bestPenalty = int.MaxValue;
        // Masks are evaluated with format and version areas still light (ISO/IEC 18004:2015 §7.8).
        for (var m = 0; m < 8; m++)
        {
            qr.ApplyMask(m);
            var p = qr.Penalty();
            if (p < bestPenalty)
            {
                best = m;
                bestPenalty = p;
            }
            qr.ApplyMask(m);
        }
        qr.Mask = best;
        qr.ApplyMask(best);
        qr.DrawFormatBits(best);
        qr.DrawVersion();
        return qr;
    }

    /// <summary>Rows of <c>0</c>/<c>1</c>, as in the <c>qr</c> vectors.</summary>
    public IReadOnlyList<string> Rows()
    {
        var rows = new List<string>(Size);
        for (var y = 0; y < Size; y++)
        {
            var sb = new StringBuilder(Size);
            for (var x = 0; x < Size; x++)
            {
                sb.Append(_modules[y, x] ? '1' : '0');
            }
            rows.Add(sb.ToString());
        }
        return rows;
    }

    private static int CountBits(int version) => version <= 9 ? 8 : 16;

    private static int RawDataModules(int ver)
    {
        var result = ((16 * ver) + 128) * ver + 64;
        if (ver >= 2)
        {
            var numAlign = (ver / 7) + 2;
            result -= ((25 * numAlign) - 10) * numAlign - 55;
            if (ver >= 7)
            {
                result -= 36;
            }
        }
        return result;
    }

    private static int DataCodewords(int ver) => (RawDataModules(ver) / 8) - (EccPerBlock[ver] * Blocks[ver]);

    private static void Append(List<bool> bits, int value, int length)
    {
        for (var i = length - 1; i >= 0; i--)
        {
            bits.Add(((value >> i) & 1) != 0);
        }
    }

    private static byte[] AddEccAndInterleave(byte[] data, int ver)
    {
        var numBlocks = Blocks[ver];
        var eccLen = EccPerBlock[ver];
        var raw = RawDataModules(ver) / 8;
        var numShort = numBlocks - (raw % numBlocks);
        var shortLen = raw / numBlocks;
        var divisor = ReedSolomonDivisor(eccLen);
        var blocks = new List<byte[]>();
        for (int i = 0, k = 0; i < numBlocks; i++)
        {
            var len = shortLen - eccLen + (i < numShort ? 0 : 1);
            var dat = data[k..(k + len)];
            k += len;
            var block = new byte[shortLen + 1];
            Array.Copy(dat, block, dat.Length);
            var ecc = ReedSolomonRemainder(dat, divisor);
            Array.Copy(ecc, 0, block, block.Length - eccLen, eccLen);
            blocks.Add(block);
        }
        var result = new List<byte>(raw);
        for (var i = 0; i < blocks[0].Length; i++)
        {
            for (var j = 0; j < blocks.Count; j++)
            {
                if (i != shortLen - eccLen || j >= numShort)
                {
                    result.Add(blocks[j][i]);
                }
            }
        }
        return [.. result];
    }

    private static byte[] ReedSolomonDivisor(int degree)
    {
        var result = new byte[degree];
        result[degree - 1] = 1;
        var root = 1;
        for (var i = 0; i < degree; i++)
        {
            for (var j = 0; j < result.Length; j++)
            {
                result[j] = (byte)Multiply(result[j], root);
                if (j + 1 < result.Length)
                {
                    result[j] ^= result[j + 1];
                }
            }
            root = Multiply(root, 0x02);
        }
        return result;
    }

    private static byte[] ReedSolomonRemainder(byte[] data, byte[] divisor)
    {
        var result = new byte[divisor.Length];
        foreach (var b in data)
        {
            var factor = b ^ result[0];
            Array.Copy(result, 1, result, 0, result.Length - 1);
            result[^1] = 0;
            for (var i = 0; i < result.Length; i++)
            {
                result[i] ^= (byte)Multiply(divisor[i], factor);
            }
        }
        return result;
    }

    private static int Multiply(int x, int y)
    {
        var z = 0;
        for (var i = 7; i >= 0; i--)
        {
            z = (z << 1) ^ ((z >> 7) * 0x11D);
            z ^= ((y >> i) & 1) * x;
        }
        return z & 0xFF;
    }

    private void Set(int x, int y, bool dark)
    {
        _modules[y, x] = dark;
        _function[y, x] = true;
    }

    private void DrawFunctionPatterns()
    {
        for (var i = 0; i < Size; i++)
        {
            Set(6, i, i % 2 == 0);
            Set(i, 6, i % 2 == 0);
        }
        DrawFinder(3, 3);
        DrawFinder(Size - 4, 3);
        DrawFinder(3, Size - 4);
        var pos = AlignmentPositions();
        for (var i = 0; i < pos.Length; i++)
        {
            for (var j = 0; j < pos.Length; j++)
            {
                if (!((i == 0 && j == 0) || (i == 0 && j == pos.Length - 1) || (i == pos.Length - 1 && j == 0)))
                {
                    DrawAlignment(pos[i], pos[j]);
                }
            }
        }
        // Reserve format and version areas as light function modules; they are drawn after masking.
        DrawFormatBits(0);
        DrawVersion();
        for (var y = 0; y < Size; y++)
        {
            for (var x = 0; x < Size; x++)
            {
                if (IsFormatOrVersion(x, y))
                {
                    _modules[y, x] = false;
                }
            }
        }
    }

    private bool IsFormatOrVersion(int x, int y) =>
        (y == 8 && x != 6 && (x <= 8 || x >= Size - 8)) || (x == 8 && y != 6 && (y <= 8 || y >= Size - 8)) ||
        (Version >= 7 && ((x >= Size - 11 && x < Size - 8 && y < 6) || (y >= Size - 11 && y < Size - 8 && x < 6)));

    private int[] AlignmentPositions()
    {
        if (Version == 1)
        {
            return [];
        }
        var numAlign = (Version / 7) + 2;
        var step = Version == 32 ? 26 : ((Version * 4) + (numAlign * 2) + 1) / ((numAlign * 2) - 2) * 2;
        var result = new int[numAlign];
        result[0] = 6;
        for (int i = numAlign - 1, p = Size - 7; i >= 1; i--, p -= step)
        {
            result[i] = p;
        }
        return result;
    }

    private void DrawFinder(int x, int y)
    {
        for (var dy = -4; dy <= 4; dy++)
        {
            for (var dx = -4; dx <= 4; dx++)
            {
                var dist = Math.Max(Math.Abs(dx), Math.Abs(dy));
                int xx = x + dx, yy = y + dy;
                if (xx >= 0 && xx < Size && yy >= 0 && yy < Size)
                {
                    Set(xx, yy, dist != 2 && dist != 4);
                }
            }
        }
    }

    private void DrawAlignment(int x, int y)
    {
        for (var dy = -2; dy <= 2; dy++)
        {
            for (var dx = -2; dx <= 2; dx++)
            {
                Set(x + dx, y + dy, Math.Max(Math.Abs(dx), Math.Abs(dy)) != 1);
            }
        }
    }

    private void DrawFormatBits(int mask)
    {
        var data = (0 << 3) | mask; // level M has format bits 00
        var rem = data;
        for (var i = 0; i < 10; i++)
        {
            rem = (rem << 1) ^ ((rem >> 9) * 0x537);
        }
        var bits = ((data << 10) | rem) ^ 0x5412;
        bool Bit(int i) => ((bits >> i) & 1) != 0;
        for (var i = 0; i <= 5; i++)
        {
            Set(8, i, Bit(i));
        }
        Set(8, 7, Bit(6));
        Set(8, 8, Bit(7));
        Set(7, 8, Bit(8));
        for (var i = 9; i < 15; i++)
        {
            Set(14 - i, 8, Bit(i));
        }
        for (var i = 0; i < 8; i++)
        {
            Set(Size - 1 - i, 8, Bit(i));
        }
        for (var i = 8; i < 15; i++)
        {
            Set(8, Size - 15 + i, Bit(i));
        }
        Set(8, Size - 8, true);
    }

    private void DrawVersion()
    {
        if (Version < 7)
        {
            return;
        }
        var rem = Version;
        for (var i = 0; i < 12; i++)
        {
            rem = (rem << 1) ^ ((rem >> 11) * 0x1F25);
        }
        var bits = (Version << 12) | rem;
        for (var i = 0; i < 18; i++)
        {
            var bit = ((bits >> i) & 1) != 0;
            int a = Size - 11 + (i % 3), b = i / 3;
            Set(a, b, bit);
            Set(b, a, bit);
        }
    }

    private void DrawCodewords(byte[] data)
    {
        var i = 0;
        for (var right = Size - 1; right >= 1; right -= 2)
        {
            if (right == 6)
            {
                right = 5;
            }
            for (var vert = 0; vert < Size; vert++)
            {
                for (var j = 0; j < 2; j++)
                {
                    var x = right - j;
                    var upward = ((right + 1) & 2) == 0;
                    var y = upward ? Size - 1 - vert : vert;
                    if (!_function[y, x] && i < data.Length * 8)
                    {
                        _modules[y, x] = ((data[i >> 3] >> (7 - (i & 7))) & 1) != 0;
                        i++;
                    }
                }
            }
        }
    }

    private void ApplyMask(int mask)
    {
        for (var y = 0; y < Size; y++)
        {
            for (var x = 0; x < Size; x++)
            {
                var invert = mask switch
                {
                    0 => (x + y) % 2 == 0,
                    1 => y % 2 == 0,
                    2 => x % 3 == 0,
                    3 => (x + y) % 3 == 0,
                    4 => ((x / 3) + (y / 2)) % 2 == 0,
                    5 => ((x * y) % 2) + ((x * y) % 3) == 0,
                    6 => (((x * y) % 2) + ((x * y) % 3)) % 2 == 0,
                    _ => (((x + y) % 2) + ((x * y) % 3)) % 2 == 0,
                };
                if (!_function[y, x] && invert)
                {
                    _modules[y, x] = !_modules[y, x];
                }
            }
        }
    }

    /// <summary>Penalty score of ISO/IEC 18004 §7.8.3 (N1 = 3, N2 = 3, N3 = 40, N4 = 10).</summary>
    private int Penalty()
    {
        var score = 0;
        var dark = 0;
        var row = new bool[Size];
        var col = new bool[Size];
        for (var i = 0; i < Size; i++)
        {
            for (var j = 0; j < Size; j++)
            {
                row[j] = _modules[i, j];
                col[j] = _modules[j, i];
                if (row[j])
                {
                    dark++;
                }
            }
            score += RunPenalty(row) + RunPenalty(col) + FinderPenalty(row) + FinderPenalty(col);
        }
        for (var y = 1; y < Size; y++)
        {
            for (var x = 1; x < Size; x++)
            {
                var c = _modules[y, x];
                if (c == _modules[y, x - 1] && c == _modules[y - 1, x] && c == _modules[y - 1, x - 1])
                {
                    score += 3;
                }
            }
        }
        var total = Size * Size;
        score += 10 * (Math.Abs((dark * 100) - (50 * total)) / (5 * total));
        return score;
    }

    private static int RunPenalty(bool[] line)
    {
        var score = 0;
        var run = 1;
        for (var i = 1; i < line.Length; i++)
        {
            if (line[i] == line[i - 1])
            {
                run++;
            }
            else
            {
                if (run >= 5)
                {
                    score += run - 2;
                }
                run = 1;
            }
        }
        if (run >= 5)
        {
            score += run - 2;
        }
        return score;
    }

    private static readonly bool[] Pattern = [true, false, true, true, true, false, true];

    /// <summary>1:1:3:1:1 pattern with four light modules (or the symbol edge) on at least one side.</summary>
    private static int FinderPenalty(bool[] line)
    {
        var n = line.Length;
        var score = 0;
        var idx = Find(line, 0);
        while (idx != -1)
        {
            var next = idx + 7;
            if (idx == 0 || idx == n - 7 || AllLight(line, Math.Max(idx - 4, 0), idx) || AllLight(line, next, Math.Min(next + 4, n)))
            {
                score += 40;
            }
            else
            {
                next = idx + 4;
            }
            idx = Find(line, next);
        }
        return score;
    }

    private static int Find(bool[] line, int from)
    {
        for (var i = from; i + 7 <= line.Length; i++)
        {
            var ok = true;
            for (var k = 0; k < 7 && ok; k++)
            {
                ok = line[i + k] == Pattern[k];
            }
            if (ok)
            {
                return i;
            }
        }
        return -1;
    }

    private static bool AllLight(bool[] line, int from, int to)
    {
        for (var i = from; i < to; i++)
        {
            if (line[i])
            {
                return false;
            }
        }
        return true;
    }
}
