// Lists voice takes whose transcript has words that are neither in the line nor Finnish number
// words (for example a number read in English). Reads work/audio/vo/<voice>/check.json of the edition.
//
//   node recheck.mjs 2026-10 harper grant
import { readFileSync } from 'node:fs';
import { edition } from './edition.mjs';

const [month, ...voices] = process.argv.slice(2);
const ed = await edition(month);

const NUM = /(nolla|yks|yhd|kaks|kahd|kolm|nelj|viis|viid|kuus|kuud|seitsem|kahdeks|yhdeks|kymmen|toista|sata|sada|tuha|pilkku|piste|miinus|plus|ensimm|toinen)/;
const norm = (s) => s.toLowerCase().replace(/[^a-zåäö\s]/g, ' ').split(/\s+/).filter((w) => w.length > 2);
for (const voice of voices) {
    const log = JSON.parse(readFileSync(`${ed.work}/audio/vo/${voice}/check.json`, 'utf8'));
    for (const [id, { text, heard }] of Object.entries(log)) {
        const want = norm(text);
        // Compound number words are split by the transcript; accept any part that starts a number word.
        const extra = norm(heard).filter((w) => !want.some((x) => x.startsWith(w.slice(0, 5)) || w.startsWith(x.slice(0, 5))) && !NUM.test(w));
        if (extra.length) console.log(`${voice} ${id}: ${extra.join(', ')}`);
    }
}
