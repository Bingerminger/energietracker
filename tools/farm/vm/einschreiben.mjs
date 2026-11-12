#!/usr/bin/env node
// Test-Farm (tools/farm/vm.sh): schreibt ein rohes Plattenabbild von stdin in eine
// vorhandene Plattendatei der VM, ab dem ersten Byte.
//
//   docker run --rm -i <alpine> xzcat < haos.img.xz | node einschreiben.mjs <ziel.hds>
//
// Das Ziel ist die rohe Datei (.hds) einer frisch angelegten Platte vom Typ „plain“;
// der Rest der Platte bleibt leer (Home Assistant OS vergrößert seine Datenpartition
// beim ersten Start). Nullblöcke werden übersprungen statt geschrieben, damit die
// Datei dünn belegt bleibt. Das Abbild stammt aus dem Netz und wird nur als Daten gelesen.
import { closeSync, fstatSync, fsyncSync, openSync, writeSync } from 'node:fs';

const BLOCK = 4 * 1024 * 1024;
const ziel = process.argv[2];
if (!ziel) { console.error('Aufruf: … | node einschreiben.mjs <ziel>'); process.exit(2); }
const fd = openSync(ziel, 'r+');
const platz = fstatSync(fd).size;
let puffer = Buffer.alloc(0);
let pos = 0;

function schreibe(block) {
    if (pos + block.length > platz) {
        console.error(`Abbild größer als die Platte (${platz} Byte)`);
        process.exit(1);
    }
    let leer = true;
    for (let i = 0; i < block.length; i += 8) {
        if (block.readBigUInt64LE(i) !== 0n) { leer = false; break; }
    }
    if (!leer) writeSync(fd, block, 0, block.length, pos);
    pos += block.length;
}

for await (const stueck of process.stdin) {
    puffer = puffer.length ? Buffer.concat([puffer, stueck]) : stueck;
    while (puffer.length >= BLOCK) {
        schreibe(puffer.subarray(0, BLOCK));
        puffer = puffer.subarray(BLOCK);
    }
}
if (puffer.length) {
    // Rest auf 8 Byte auffüllen (nur für den Nulltest), geschrieben wird die echte Länge
    const rest = Buffer.alloc(Math.ceil(puffer.length / 8) * 8);
    puffer.copy(rest);
    const leer = rest.every((b) => b === 0);
    if (!leer) writeSync(fd, puffer, 0, puffer.length, pos);
    pos += puffer.length;
}
fsyncSync(fd);
closeSync(fd);
console.log(pos);
