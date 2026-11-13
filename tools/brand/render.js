// Renders the brand PNGs from assets/brand/*.svg with a headless Chromium (Playwright).
// Development tool only; the PNGs are committed. Usage: node tools/brand/render.js
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const mark = fs.readFileSync(path.join(root, 'assets/brand/logo.svg'), 'utf8');
const font = "font-family:Inter,'Segoe UI',Helvetica,Arial,sans-serif;";

const page = (w, h, body, bg = '#ffffff') => `<!doctype html><html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0;width:${w}px;height:${h}px;overflow:hidden;background:${bg};${font}}
.mark svg{width:100%;height:100%;display:block}
</style></head><body>${body}</body></html>`;

const markOnly = (size) => page(size, size, `<div class="mark" style="width:${size}px;height:${size}px">${mark}</div>`, 'transparent');

const banner = (w, h) => {
  const s = h / 250;
  return page(w, h, `
<div style="position:relative;width:${w}px;height:${h}px;background:linear-gradient(120deg,#1B1B1F 0%,#2A2A31 55%,#8E0B20 100%);color:#fff;">
  <svg viewBox="0 0 772 250" width="${w}" height="${h}" style="position:absolute;inset:0" preserveAspectRatio="none">
    <path d="M0 210 L120 130 L190 175 L300 95 L420 180 L520 120 L640 190 L772 140 V250 H0 Z" fill="#ffffff" opacity=".05"/>
  </svg>
  <div class="mark" style="position:absolute;left:${40 * s}px;top:${45 * s}px;width:${160 * s}px;height:${160 * s}px">${mark}</div>
  <div style="position:absolute;left:${230 * s}px;top:${62 * s}px;right:${30 * s}px">
    <div style="font-size:${52 * s}px;font-weight:700;letter-spacing:-0.02em;line-height:1">Monte<span style="color:#FF5468">Fiskal</span></div>
    <div style="font-size:${24 * s}px;font-weight:600;margin-top:${10 * s}px;color:#E6E6EA">for WooCommerce</div>
    <div style="font-size:${17 * s}px;margin-top:${20 * s}px;color:#C9CCD3">EFI fiscalization for Montenegro · IKOF · JIKR · QR</div>
  </div>
</div>`);
};

const social = () => page(1280, 640, `
<div style="position:relative;width:1280px;height:640px;background:linear-gradient(135deg,#FFFFFF 0%,#F4F4F6 60%,#FBE9EC 100%);">
  <svg viewBox="0 0 1280 640" width="1280" height="640" style="position:absolute;inset:0">
    <path d="M0 520 L200 380 L320 470 L520 300 L720 460 L880 360 L1060 480 L1280 380 V640 H0 Z" fill="#C8102E" opacity=".07"/>
  </svg>
  <div class="mark" style="position:absolute;left:96px;top:150px;width:240px;height:240px">${mark}</div>
  <div style="position:absolute;left:380px;top:160px;right:80px;color:#1B1B1F">
    <div style="font-size:96px;font-weight:800;letter-spacing:-0.03em;line-height:1">Monte<span style="color:#C8102E">Fiskal</span></div>
    <div style="font-size:34px;font-weight:500;margin-top:22px;color:#3A3A42;line-height:1.3">Open-source EFI fiscalization for Montenegro</div>
    <div style="margin-top:34px;display:flex;gap:14px;font-size:24px;font-weight:600">
      <span style="padding:10px 20px;border-radius:999px;background:#1B1B1F;color:#fff">.NET 10</span>
      <span style="padding:10px 20px;border-radius:999px;background:#1B1B1F;color:#fff">PHP 8.5</span>
      <span style="padding:10px 20px;border-radius:999px;background:#C8102E;color:#fff">WooCommerce</span>
    </div>
  </div>
</div>`);

(async () => {
  const browser = await chromium.launch();
  const shot = async (html, w, h, out, transparent = false) => {
    const p = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await p.setContent(html, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    fs.mkdirSync(path.dirname(out), { recursive: true });
    await p.screenshot({ path: out, omitBackground: transparent, clip: { x: 0, y: 0, width: w, height: h } });
    await p.close();
    console.log(path.relative(root, out));
  };
  const plugin = path.join(root, 'plugins/montefiskal-woocommerce/assets/brand');
  await shot(markOnly(128), 128, 128, path.join(root, 'assets/nuget/icon.png'), true);
  await shot(markOnly(512), 512, 512, path.join(root, 'assets/brand/logo-512.png'), true);
  await shot(markOnly(128), 128, 128, path.join(plugin, 'icon-128x128.png'), true);
  await shot(markOnly(256), 256, 256, path.join(plugin, 'icon-256x256.png'), true);
  await shot(banner(772, 250), 772, 250, path.join(plugin, 'banner-772x250.png'));
  await shot(banner(1544, 500), 1544, 500, path.join(plugin, 'banner-1544x500.png'));
  await shot(social(), 1280, 640, path.join(root, 'assets/social/social-preview.png'));
  await browser.close();
})();
