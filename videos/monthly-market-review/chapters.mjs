// Prints a YouTube description with chapters for each variant: final/<file>-description.txt.
//
//   node chapters.mjs
//
// Chapter starts are the first voice line of each part (YouTube needs 0:00 first and 10 s parts).
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const CH = {
    katsaus: [['Lokakuu oli halpa, talvesta tulee kallis', null], ['Miksi talvesta tulee kallis', 'k_win1'], ['Mikä sopimus nyt kannattaa', 'k_adv1'],
        ['Sähkölämmitteinen pientalo', 'k_be02_1'], ['Pientalo ilman sähkölämmitystä', 'k_be03_1'], ['Kerrostaloasunto', 'k_be01_1'], ['Kvartaalisähkö', 'k_q1'], ['Sopimusmarkkina', 'k_mkt1'],
        ['Lokakuu lyhyesti', 'k_oct0']],
    kotia: [['Kiinteä vai pörssisähkö?', null], ['Halpa lokakuu, kallis talvi', 'h_ctx1'], ['Sähkölämmitteinen pientalo', 'h_be02_0'], ['Pientalo ilman sähkölämmitystä', 'k_be03_1'],
        ['Kerrostaloasunto', 'h_be01_0'], ['Kvartaalisähkö', 'h_q0']],
    numerot: [['Lokakuu', null], ['Talvi', 'n_6'], ['Mitä valita', 'n_11'], ['Sopimukset', 'n_14'], ['Neuvomme', 'n_sum']],
    saa: [['Voltikan sähkösää', null], ['Lokakuun sää', 's_oct1'], ['Sääkartta', 's_map0'], ['Ennuste', 's_fc0'], ['Sähkövaroitus', 's_warn0'], ['Sopimusmarkkinan ilmanpaine', 's_mkt0'], ['Näin varaudut talveen', 's_adv0']],
};
// YouTube title and the text above the chapters, per variant (the other variants keep the plain first line).
const TITLE = {
    katsaus: 'Sähkön hinta talvella: pörssisähkö vai kiinteä? Sähkömarkkinakatsaus lokakuu 2026',
};
const INTRO = {
    katsaus: `Lokakuu oli halpa, mutta talvesta tulee kallis. Pörssisähkö maksoi lokakuussa keskimäärin 4,77 c/kWh, ja tammikuun futuuri on jo 15,7 c/kWh. Kerromme, miksi talvesta tulee kallis ja mikä sähkösopimus nyt kannattaa kolmessa tavallisessa kodissa.

Neuvomme lyhyesti:
• Sähkölämmitteinen pientalo: pörssisähkö on pitkällä aikavälillä halvempi, mutta tuleva talvi on sille riski. Jos haluat kiinteän hinnan, valitse 24 kuukautta.
• Pientalo ilman sähkölämmitystä: pysy pörssisähkössä.
• Kerrostaloasunto: pysy pörssisähkössä.
• Kvartaalisähkö: hinta nousee 1.1. noin 3,8 c/kWh. Vaihda ennen vuodenvaihdetta pörssisähköön tai 24 kuukauden kiinteään hintaan.

Myyjät nostivat energiahintojaan lokakuussa 12 %, vaikka pörssisähkö halpeni kolmanneksen.`,
};
const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
mkdirSync('final', { recursive: true });
mkdirSync('out/archive', { recursive: true });
// Default: only the published variant (final/ holds only final outputs). Other variants: node chapters.mjs kotia
const only = process.argv.slice(2).length ? process.argv.slice(2) : ['katsaus'];
for (const [v, list] of Object.entries(CH).filter(([v]) => only.includes(v))) {
    globalThis.window = {};
    eval(readFileSync(`timeline/${v}.js`, 'utf8'));
    const L = window.TL.lines;
    const lines = list.map(([name, id]) => `${mmss(id ? Math.max(0, L[id].start - 0.5) : 0)} ${name}`);
    const text = `${INTRO[v] ?? 'Voltikan sähkömarkkinakatsaus: lokakuu 2026 ja näkymät talveen.'}

${lines.join('\n')}

Laske sopimusten hinnat omalla kulutuksellasi: https://voltikka.fi/sahkosopimus
${v === 'katsaus' ? 'Koko katsaus lukuineen ja kuvineen: https://voltikka.fi/markkinakatsaus/2026-10\n' : ''}
Laskelmat: sähköenergia ja sähkövero (2,918 c/kWh alv mukaan lukien), ei siirtomaksuja. Kodit: Fingrid Datahub (keskimääräinen kerrostaloasunto, pientalo ilman sähkölämmitystä ja sähkölämmitteinen pientalo). Tulevat hinnat: Suomen sähköfutuurit (EEX) 30.10.2026 ja kolmen menneen talven tuntivaihtelu. Sopimukset: Voltikan päivittäin keräämät hinnat 2.11.2026.
Lähteet: ENTSO-E, Fingrid (CC BY 4.0), Open-Meteo.com (ERA5, ECMWF), NVE, GIE AGSI, EEX, Voltikka.
${v === 'katsaus' ? '\n#sähkö #pörssisähkö #sähkösopimus\n' : ''}`;
    writeFileSync(`${v === 'katsaus' ? 'final' : 'out/archive'}/voltikka-markkinakatsaus-2026-10-${v}-description.txt`, text);
    if (TITLE[v]) writeFileSync(`final/voltikka-markkinakatsaus-2026-10-${v}-title.txt`, TITLE[v] + '\n');
    console.log(`== ${v}\n${lines.join('\n')}`);
}
