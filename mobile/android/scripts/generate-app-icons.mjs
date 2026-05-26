#!/usr/bin/env node
/**
 * Ikony APK ze stejného SVG jako /public/favicon.svg
 * Vyžaduje: npm install (sharp)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const androidRes = path.join(root, 'android', 'app', 'src', 'main', 'res');

const fullSvg = path.join(root, 'assets', 'icon-full.svg');
const fgSvg = path.join(root, 'assets', 'icon-foreground.svg');

const launcherSizes = {
  'mipmap-mdpi': 48,
  'mipmap-hdpi': 72,
  'mipmap-xhdpi': 96,
  'mipmap-xxhdpi': 144,
  'mipmap-xxxhdpi': 192,
};

const foregroundSizes = {
  'mipmap-mdpi': 108,
  'mipmap-hdpi': 162,
  'mipmap-xhdpi': 216,
  'mipmap-xxhdpi': 324,
  'mipmap-xxxhdpi': 432,
};

async function pngFromSvg(svgPath, size, outPath) {
  await sharp(svgPath, { density: Math.max(144, Math.ceil(size * 2)) })
    .resize(size, size)
    .png()
    .toFile(outPath);
}

async function main() {
  if (!fs.existsSync(androidRes)) {
    console.error('Chybí android/app/src/main/res — nejdřív: npx cap add android');
    process.exit(1);
  }

  for (const [folder, size] of Object.entries(launcherSizes)) {
    const dir = path.join(androidRes, folder);
    fs.mkdirSync(dir, { recursive: true });
    const launcher = path.join(dir, 'ic_launcher.png');
    const round = path.join(dir, 'ic_launcher_round.png');
    await pngFromSvg(fullSvg, size, launcher);
    await fs.promises.copyFile(launcher, round);
    console.log('wrote', launcher);
  }

  for (const [folder, size] of Object.entries(foregroundSizes)) {
    const out = path.join(androidRes, folder, 'ic_launcher_foreground.png');
    await pngFromSvg(fgSvg, size, out);
    console.log('wrote', out);
  }

  const bgXml = path.join(androidRes, 'values', 'ic_launcher_background.xml');
  fs.writeFileSync(
    bgXml,
    `<?xml version="1.0" encoding="utf-8"?>\n<resources>\n    <color name="ic_launcher_background">#FFCC00</color>\n</resources>\n`,
  );
  console.log('wrote', bgXml, '(#FFCC00 jako favicon)');
  console.log('\nHotovo. Přebuilduj APK: npm run android:assembleDebug');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
