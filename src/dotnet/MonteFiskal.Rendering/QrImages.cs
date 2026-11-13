using System.Buffers.Binary;
using System.Globalization;
using System.IO.Compression;
using System.Text;

namespace MonteFiskal.Rendering;

/// <summary>SVG and PNG output of a <see cref="QrCode"/>. Default quiet zone is four modules.</summary>
public static class QrImages
{
    /// <summary>SVG sized in millimetres; BR-Q8 asks for at least 21 mm × 21 mm on paper.</summary>
    public static string Svg(QrCode qr, double sizeMillimetres = 25, int quietZone = 4)
    {
        ArgumentNullException.ThrowIfNull(qr);
        if (sizeMillimetres < 21)
        {
            throw new ArgumentOutOfRangeException(nameof(sizeMillimetres), "BR-Q8: the printed QR code is at least 21 mm.");
        }
        var n = qr.Size + (2 * quietZone);
        var sb = new StringBuilder();
        var mm = sizeMillimetres.ToString("0.##", CultureInfo.InvariantCulture);
        sb.Append(CultureInfo.InvariantCulture, $"<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{mm}mm\" height=\"{mm}mm\" viewBox=\"0 0 {n} {n}\" shape-rendering=\"crispEdges\">");
        sb.Append(CultureInfo.InvariantCulture, $"<rect width=\"{n}\" height=\"{n}\" fill=\"#fff\"/><path fill=\"#000\" d=\"");
        for (var y = 0; y < qr.Size; y++)
        {
            for (var x = 0; x < qr.Size; x++)
            {
                if (qr[x, y])
                {
                    sb.Append(CultureInfo.InvariantCulture, $"M{x + quietZone} {y + quietZone}h1v1h-1z");
                }
            }
        }
        sb.Append("\"/></svg>");
        return sb.ToString();
    }

    /// <summary>Grayscale PNG with <paramref name="scale"/> pixels per module.</summary>
    public static byte[] Png(QrCode qr, int scale = 8, int quietZone = 4)
    {
        ArgumentNullException.ThrowIfNull(qr);
        var n = (qr.Size + (2 * quietZone)) * scale;
        var raw = new byte[n * (n + 1)];
        for (var py = 0; py < n; py++)
        {
            raw[py * (n + 1)] = 0; // filter type none
            for (var px = 0; px < n; px++)
            {
                int mx = (px / scale) - quietZone, my = (py / scale) - quietZone;
                var dark = mx >= 0 && my >= 0 && mx < qr.Size && my < qr.Size && qr[mx, my];
                raw[(py * (n + 1)) + 1 + px] = dark ? (byte)0 : (byte)255;
            }
        }
        return PngWriter.Grayscale(n, n, raw);
    }
}

/// <summary>Minimal PNG writer (8-bit grayscale), used for QR codes and test images.</summary>
public static class PngWriter
{
    /// <summary>Writes a PNG from filtered scanlines (each row starts with its filter byte).</summary>
    public static byte[] Grayscale(int width, int height, byte[] filteredScanlines)
    {
        using var ms = new MemoryStream();
        ms.Write([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]);
        var ihdr = new byte[13];
        BinaryPrimitives.WriteInt32BigEndian(ihdr, width);
        BinaryPrimitives.WriteInt32BigEndian(ihdr.AsSpan(4), height);
        ihdr[8] = 8; // bit depth
        ihdr[9] = 0; // grayscale
        Chunk(ms, "IHDR", ihdr);
        using (var z = new MemoryStream())
        {
            using (var zs = new ZLibStream(z, CompressionLevel.SmallestSize, leaveOpen: true))
            {
                zs.Write(filteredScanlines);
            }
            Chunk(ms, "IDAT", z.ToArray());
        }
        Chunk(ms, "IEND", []);
        return ms.ToArray();
    }

    private static void Chunk(Stream s, string type, byte[] data)
    {
        Span<byte> len = stackalloc byte[4];
        BinaryPrimitives.WriteInt32BigEndian(len, data.Length);
        s.Write(len);
        var t = Encoding.ASCII.GetBytes(type);
        s.Write(t);
        s.Write(data);
        var crc = Crc32(t, data);
        BinaryPrimitives.WriteUInt32BigEndian(len, crc);
        s.Write(len);
    }

    private static readonly uint[] Table = BuildTable();

    private static uint[] BuildTable()
    {
        var table = new uint[256];
        for (uint n = 0; n < 256; n++)
        {
            var c = n;
            for (var k = 0; k < 8; k++)
            {
                c = (c & 1) != 0 ? 0xEDB88320u ^ (c >> 1) : c >> 1;
            }
            table[n] = c;
        }
        return table;
    }

    private static uint Crc32(byte[] a, byte[] b)
    {
        var c = 0xFFFFFFFFu;
        foreach (var x in a)
        {
            c = Table[(c ^ x) & 0xFF] ^ (c >> 8);
        }
        foreach (var x in b)
        {
            c = Table[(c ^ x) & 0xFF] ^ (c >> 8);
        }
        return c ^ 0xFFFFFFFFu;
    }
}
