// =====================================================================
// Energietracker v3.1.0 — Fotos für Belege verkleinern (Paket H2, MKT-08)
//
// Ein Handyfoto hat 3–12 MB und trägt in den EXIF-Daten oft den Ort. Vor dem
// Hochladen wird es im Browser auf höchstens 1600 px Kantenlänge gebracht und
// als JPEG (Qualität 0,8) neu kodiert — das Zählwerk bleibt lesbar, die Datei
// hat einige hundert KB, und die EXIF-Daten samt GPS fallen dabei weg.
// =====================================================================

/**
 * @param {Blob} file  Bild aus `<input type="file" accept="image/*">`
 * @returns {Promise<Blob>} JPEG
 */
export async function shrinkPhoto(file, { max = 1600, quality = 0.8 } = {}) {
  const src = await decode(file);
  const scale = Math.min(1, max / Math.max(src.width, src.height));
  const w = Math.max(1, Math.round(src.width * scale));
  const h = Math.max(1, Math.round(src.height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  canvas.getContext('2d').drawImage(src, 0, 0, w, h);
  src.close?.();
  return new Promise((resolve, reject) => {
    canvas.toBlob(b => (b ? resolve(b) : reject(new Error('toBlob'))), 'image/jpeg', quality);
  });
}

async function decode(file) {
  if (typeof createImageBitmap === 'function') {
    try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch { /* ältere Browser: über <img> */ }
  }
  const url = URL.createObjectURL(file);
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('decode'));
      img.src = url;
    });
  } finally {
    setTimeout(() => URL.revokeObjectURL(url), 0);
  }
}
