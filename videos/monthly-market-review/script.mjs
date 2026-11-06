// Voice lines and the line order of each video variant.
//
// One line = one voice file (audio/vo/<voice>/<id>.wav). A line that two variants share is
// generated once per voice. The scenes read the start times from timeline/<variant>.js, which
// timeline.mjs makes from the measured voice durations. Figures: tasks/monthly-market-review/
// prototype/2026-10-katsaus.md (edition 5.11.2026) and its JSON files.
//
// Write numbers so that the Finnish voice reads them well: digits are fine ("16", "1 150"),
// but no units in short form (c/kWh, €, %) and no ranges with a dash.
// A number in a case other than the nominative ("24 kuukauden") is written in words in the
// correct case ("kahdenkymmenenneljän kuukauden"); MAI reads digits in the nominative.
// Do not say a number with two decimals (user 2026-11-06: it does not sound natural). Round it in
// the voice ("4,77" → "alle viisi senttiä", "3,7 %" → "lähes neljä prosenttia", "1 564 €" → "noin
// 1 560" or a difference); the screen keeps the exact figure.

export const LINES = {
    // ---------- Katsaus (full review) ----------
    k_intro1: 'Tämä on Voltikan sähkömarkkinakatsaus lokakuulta 2026.',
    k_intro2: 'Lokakuu oli halpa. Talvesta tulee kallis.',
    k_intro3: 'Kerromme, miksi talvesta tulee kallis ja mikä sähkösopimus kannattaa nyt valita.',

    k_oct0: 'Lopuksi lyhyesti lokakuusta.',
    k_oct1: 'Pörssisähkö maksoi lokakuussa keskimäärin alle viisi senttiä kilowattitunnilta.',
    k_oct2: 'Se on kolmanneksen vähemmän kuin syyskuussa ja viidennes vähemmän kuin vuotta aiemmin.',
    k_oct3: 'Hintaa painoivat alas tuuli ja lämmin sää.',
    k_oct4: 'Tuulivoimaa tuotettiin 23 prosenttia enemmän kuin vuotta aiemmin.',
    k_oct5: 'Lokakuu oli lähes kolme astetta tavallista lämpimämpi, joten lämmitystä tarvittiin neljännes tavallista vähemmän.',
    k_oct6: 'Hinta pysyi alhaalla, vaikka ydinvoimasta oli käytössä alle puolet.',
    k_oct7: 'Hinta oli korkeimmillaan 30. lokakuuta, kun tuuli heikkeni ja Olkiluoto 1 oli vuorokauden pois verkosta.',
    k_oct8: 'Suomen hinta asettui Pohjois-Ruotsin ja Etelä-Ruotsin hintojen väliin, ja se oli selvästi Viron hintaa alempi.',

    k_win1: 'Halpa sähkö loppuu marraskuussa.',
    k_win2: 'Sähköpörssissä tammikuun sähkö maksaa nyt lähes 16 senttiä kilowattitunnilta. Se on yli kolme kertaa lokakuun hinta.',
    k_win3: 'Odotamme, että pörssisähkö maksaa marraskuusta maaliskuuhun yhdestätoista kuuteentoista senttiä.',
    k_win4: 'Syitä on kolme.',
    k_win5: 'Norjan vesialtaissa on 13 prosenttiyksikköä tavallista vähemmän vettä.',
    k_win6: 'Euroopan kaasuvarastot ovat vajaammat kuin vuosi sitten.',
    k_win7: 'Ja Olkiluoto 3 palaa vuosihuollosta vasta 11. marraskuuta.',
    k_win8: 'Marraskuun ensimmäinen maanantai näytti, mitä tämä tarkoittaa.',
    k_win9: 'Marraskuun ensimmäisenä maanantaina tuulivoimaa oli vain kolmannes lokakuun tasosta. Illalla pörssisähkö maksoi 51 senttiä kilowattitunnilta.',
    k_win10: 'Pahin riski on kylmä ja tyyni viikko, kuten tammikuussa 2024. Silloin pörssisähkö maksaa viikon ajan noin 34 senttiä.',
    k_win11: 'Lämmin syksy painoi hinnat selvästi futuurien alle. Sen varaan ei kannata laskea talvea.',

    k_adv1: 'Laskimme talven sähkölaskun kolmelle tavalliselle kodille.',
    k_adv2: 'Mukana ovat sähköenergia ja sähkövero. Siirtomaksut eivät ole mukana.',
    k_be02_1: 'Sähkölämmitteinen pientalo käyttää noin 11 000 kilowattituntia vuodessa.',
    k_be02_2: 'Marraskuusta maaliskuuhun pörssisähkö maksaa sille noin 150 euroa enemmän kuin tavallinen kiinteä hinta. Kylmänä talvena ero on noin 190 euroa.',
    k_be02_3: 'Kesällä tilanne kääntyy. Huhtikuusta lokakuuhun kiinteä hinta maksaa noin 225 euroa enemmän. Koko vuonna pörssisähkö on noin 75 euroa halvempi.',
    k_be02_4: 'Siksi neuvomme: pörssisähkö on pitkällä aikavälillä halvempi, mutta tuleva talvi on sille riski. Kiinteä hinta antaa varmuuden talven laskusta, mutta siitä maksat kesällä.',
    k_be02_5: 'Jos haluat kiinteän hinnan, valitse 24 kuukautta. Se kattaa kaksi talvea, ja se on halvempi kuin kahdentoista kuukauden sopimus.',
    k_be02_6: 'Edullisin on Pohjois-Karjalan Sähkön Optimi takuu. Ensimmäinen vuosi maksaa noin 1 500 euroa. Edullisin pörssisähkö, Herrforsin Spot plus, maksaa noin 1 560 euroa.',
    k_be02_7: 'Toisena vuonna pörssisähkö on noin 175 euroa halvempi, joten kahdessa vuodessa se on noin 115 euroa edullisempi. Kolmen vuoden sopimus maksaa kolmessa vuodessa noin 290 euroa enemmän kuin pörssisähkö.',
    k_be02_8: 'Jos siirrät kulutusta automatiikalla halvoille tunneille, säästät pörssisähköllä vielä noin 80 euroa vuodessa.',
    k_be03_1: 'Pientalo ilman sähkölämmitystä käyttää vajaat 7 000 kilowattituntia vuodessa.',
    k_be03_2: 'Sille pörssisähkö ja edullisin pitkä kiinteä hinta maksavat vuodessa lähes saman verran, vajaat tuhat euroa.',
    k_be03_3: 'Valitse pörssisähkö tai edullisin pitkä kiinteä hinta. Keskihintainen kahdentoista kuukauden sopimus maksaa noin 65 euroa enemmän.',
    k_be01_1: 'Kerrostaloasunnossa sähköä kuluu noin 2 000 kilowattituntia vuodessa.',
    k_be01_2: 'Pysy pörssisähkössä. Se on vuodessa noin 40 euroa kiinteää halvempi, ja kalleinkin talviviikko maksaa vain noin 30 euroa. Edullisin on Nordic Green Energyn Pörssisähkö.',
    k_q1: 'Kvartaalisopimusten hinta nousee vuodenvaihteessa lähes neljä senttiä kilowattitunnilta.',
    k_q2: 'Sähkölämmitteisessä pientalossa tammikuusta maaliskuuhun maksaa silloin noin 155 euroa enemmän kuin nyt. Vaihda ennen vuodenvaihdetta pörssisähköön tai kahdenkymmenenneljän kuukauden kiinteään hintaan.',

    k_mkt1: 'Sitten sopimusmarkkina.',
    k_mkt2: 'Myyjät nostivat energiahintojaan, vaikka pörssisähkö halpeni lokakuussa kolmanneksen.',
    k_mkt3: 'Voltikan hintaindeksi, joka seuraa myyjien energiahintoja, nousi lokakuussa 12 prosenttia. Suurin osa noususta tuli kuun vaihteessa, kun kvartaali- ja kuukausihintaisten sopimusten uudet hinnat alkoivat.',
    k_mkt4: 'Myyjät hinnoittelevat tulevaa talvea, eivät mennyttä kuukautta.',
    k_mkt5: 'Pitkät sopimukset ovat nyt lyhyitä halvempia. Kahden vuoden sopimus maksaa keskimäärin alle 11 senttiä ja puolen vuoden sopimus noin 15 senttiä, koska se kattaa lähes pelkkää talvea.',
    k_mkt6: 'Syksyllä tehty kuuden kuukauden sopimus kattaa lähes pelkkää talvea. Kahden vuoden sopimuksen hinnassa on mukana myös vuosi 2028, jonka futuuri on halvempi.',

    k_fc1: 'Marraskuun kaksi ensimmäistä viikkoa näyttävät tavallista lämpimämmiltä, joten marraskuu jää futuuria halvemmaksi.',
    k_fc2: 'Määräaikaisten sopimusten hinnat pysyvät ennusteemme mukaan ennallaan joulukuun alkuun.',

    k_out1: 'Laske sopimusten hinnat omalla kulutuksellasi Voltikassa. Osoite on voltikka piste fi.',
    k_out2: 'Seuraava katsaus ilmestyy joulukuun alussa.',

    // ---------- Kolme kotia (consumer advice) ----------
    h_open1: 'Kiinteä hinta vai pörssisähkö? Tänä syksynä vastaus riippuu kodistasi.',
    h_open2: 'Tämä on Voltikan sähkömarkkinakatsaus lokakuulta 2026. Laskimme talven sähkölaskun kolmelle tavalliselle kodille.',
    h_ctx1: 'Lokakuu oli halpa. Pörssisähkö maksoi keskimäärin 4,77 senttiä kilowattitunnilta.',
    h_ctx2: 'Talvesta tulee kallis. Tammikuun sähkö maksaa sähköpörssissä nyt 15,7 senttiä.',
    h_ctx3: 'Norjan vesialtaat ovat tavallista vajaammat, kaasuvarastot ovat vajaammat kuin vuosi sitten, ja Olkiluoto 3 palaa huollosta vasta 11. marraskuuta.',
    h_ctx4: 'Marraskuun ensimmäisenä maanantaina tuuli heikkeni, ja pörssisähkö maksoi illalla 51 senttiä.',
    h_be02_0: 'Aloitetaan kodista, jolla on eniten pelissä.',
    h_be01_0: 'Ja lopuksi kerrostaloasunto.',
    h_q0: 'Vielä yksi ryhmä: kvartaalisähkön asiakkaat.',
    h_note: 'Luvuissa ovat mukana sähköenergia ja sähkövero. Siirtomaksut eivät ole mukana.',
    h_out1: 'Laske oma laskusi omalla kulutuksellasi osoitteessa voltikka.fi.',

    // ---------- Numerot (big numbers) ----------
    n_open: 'Lokakuun sähkömarkkina kahdeksassatoista numerossa.',
    n_1: 'Neljä pilkku seitsemänkymmentäseitsemän. Senttiä kilowattitunnilta. Sen verran pörssisähkö maksoi lokakuussa.',
    n_2: 'Miinus 33 prosenttia. Kolmanneksen halvempaa kuin syyskuussa.',
    n_3: 'Plus 23 prosenttia. Tuulivoimaa tuli lähes neljännes enemmän kuin vuotta aiemmin.',
    n_4: 'Plus 2,9 astetta. Lokakuu oli tavallista lämpimämpi.',
    n_5: '46,5 prosenttia. Vain alle puolet ydinvoimasta oli käytössä. Silti sähkö oli halpaa.',
    n_6: '15,7. Sen verran tammikuun sähkö maksaa nyt sähköpörssissä. Yli kolme kertaa lokakuun hinta.',
    n_7: 'Miinus 13 prosenttiyksikköä. Norjan vesialtaat ovat tavallista vajaammat.',
    n_8: '11. marraskuuta. Silloin Olkiluoto 3 palaa vuosihuollosta.',
    n_9: '51 senttiä. Pörssisähkön hinta marraskuun ensimmäisen maanantain iltana, kun tuuli heikkeni.',
    n_10: '34 senttiä. Sen verran pörssisähkö maksaa kylmänä ja tyynenä talviviikkona.',
    n_11: '1 495 euroa. Edullisin 24 tai 36 kuukauden kiinteä hinta ensimmäisenä vuonna sähkölämmitteisessä pientalossa. Edullisin pörssisähkö maksaa 1 564 euroa.',
    n_12: '225 euroa. Sen verran enemmän 12 kuukauden kiinteä hinta maksaa huhtikuusta lokakuuhun. Se on huonoin valinta.',
    n_13: '155 euroa. Sen verran enemmän tammikuusta maaliskuuhun maksaa kvartaalisähkö sähkölämmitteisessä pientalossa. Hinta nousee vuodenvaihteessa.',
    n_14: 'Plus 36 prosenttia. Myyjien energiahinnat ovat nousseet toukokuun pohjalta. Myyjät hinnoittelevat jo talvea.',
    n_oct30: '18,6 senttiä. Lokakuun kallein päivä oli 30. lokakuuta, kun tuuli tyyntyi ja Olkiluoto 1 oli vuorokauden pois verkosta.',
    n_flex: '77 euroa. Sen verran sähkölämmitteinen pientalo säästää vuodessa pörssisähköllä, jos automatiikka siirtää kulutusta halvoille tunneille.',
    n_apt: '40 euroa. Sen verran pörssisähkö on kerrostaloasunnossa vuodessa kiinteää halvempi. Kalleinkin talviviikko maksaa noin 30 euroa.',
    n_terms: '10,9 senttiä. Kahden vuoden sopimuksen mediaanihinta. Puolen vuoden sopimus maksaa 14,9 senttiä, koska se kattaa lähes pelkkää talvea.',
    n_sum: 'Sähkölämmitteiseen pientaloon kannattaa nyt valita 24 kuukauden kiinteä hinta. Kerrostaloasunnossa kannattaa pysyä pörssisähkössä.',

    // ---------- Sähkösää (weather forecast format) ----------
    s_open1: 'Hyvää iltaa. Tämä on Voltikan sähkösää. Katsomme, millainen sähkökuukausi takana on ja millainen talvi on edessä.',
    s_oct1: 'Lokakuu oli lämmin ja tuulinen. Ja kun tuulee, sähkö on halpaa.',
    s_oct2: 'Pörssisähkö maksoi keskimäärin 4,77 senttiä kilowattitunnilta. Se on kolmanneksen vähemmän kuin syyskuussa.',
    s_map0: 'Katsotaan karttaa.',
    s_map1: 'Länsirannikolla Olkiluoto 3 oli vuosihuollossa koko kuun, ja myös Loviisan voimalat olivat osan kuusta huollossa.',
    s_map2: 'Silti tuulivoimaa riitti. Sitä tuotettiin 23 prosenttia enemmän kuin vuotta aiemmin.',
    s_map3: 'Pohjois-Ruotsista tuli halpaa sähköä. Viroon Suomi vei sähköä, koska siellä hinta oli korkeampi.',
    s_fc0: 'Sitten ennuste.',
    s_fc1: 'Talvi on tulossa, ja sähkö kallistuu. Marraskuun futuuri on yksitoista pilkku kolme senttiä kilowattitunnilta. Tammikuusta maaliskuuhun futuuri on 14,5 senttiä.',
    s_fc2: 'Painetta luovat Norjan vajaat vesialtaat ja Euroopan vajaammat kaasuvarastot.',
    s_fc3: 'Marraskuun alku on onneksi tavallista lämpimämpi ja tuulisempi. Siksi marraskuu jää futuuria halvemmaksi.',
    s_warn0: 'Ja sitten varoitus.',
    s_warn1: 'Kun on kylmää ja tyyntä, pörssisähkö kallistuu nopeasti. Marraskuun ensimmäisenä maanantaina se maksoi illalla 51 senttiä.',
    s_warn2: 'Kylmä ja tyyni viikko, kuten tammikuussa 2024, nostaisi viikon hinnan noin 34 senttiin.',
    s_adv0: 'Näin varaudut talveen.',
    s_adv1: 'Sähkölämmitteinen pientalo: valitse nyt 24 kuukauden kiinteä hinta. Se poistaa kalliin tammikuun riskin.',
    s_adv2: 'Pientalo ilman sähkölämmitystä: pörssisähkö tai edullisin pitkä kiinteä hinta. Molemmat maksavat vuodessa vajaat tuhat euroa.',
    s_adv3: 'Kerrostaloasunto: pysy pörssisähkössä. Kalleinkin talviviikko maksaa noin 30 euroa.',
    s_adv4: 'Ja jos sinulla on kvartaalisähkö, hintasi nousee vuodenvaihteessa. Vaihda kiinteään ennen sitä.',
    s_oct3: 'Kuun kallein päivä oli 30. lokakuuta. Tuuli tyyntyi, Olkiluoto 1 oli vuorokauden pois verkosta, ja hinta nousi 18,6 senttiin.',
    s_mkt0: 'Sitten sopimusmarkkinan ilmanpaine.',
    s_mkt1: 'Myyjien energiahinnat nousivat lokakuussa 3,7 prosenttia, vaikka pörssisähkö halpeni. Toukokuun pohjalta nousua on 36 prosenttia.',
    s_mkt2: 'Pitkät sopimukset ovat nyt lyhyitä halvempia. Kahden vuoden sopimus maksaa keskimäärin 10,9 senttiä ja puolen vuoden sopimus 14,9 senttiä.',
    s_out1: 'Sähkösää palaa joulukuun alussa. Laske oman kotisi hinnat osoitteessa voltikka.fi. Hyvää illan jatkoa.',
};

// Each segment: [line id, pause after in seconds]. A pause also gives the picture time to change.
export const VARIANTS = {
    katsaus: {
        title: 'Sähkömarkkinakatsaus · lokakuu 2026',
        // Voice: Gemini 3.8 Flash TTS Kore (user 2026-11-06: "more dynamic and interesting" than ElevenLabs v4
        // Sarah; MAI Harper was slow and read "24 kuukauden" in the nominative). Takes: audio/vo/gemini-kore/.
        model: 'google/gemini-3.8-flash-tts',
        voice: 'Kore',
        vo: 'gemini-kore',
        format: 'pcm',
        instructions: 'Speak Finnish like an energetic, confident news presenter on a consumer finance show: brisk tempo, clear and lively, friendly but authoritative. Not sleepy, not monotone.',
        music: 'audio/music/explainer.mp3',
        musicDb: -4,
        lead: 0.8,
        // Pauses (user 2026-11-06, v2 was rushed): about 0.7–0.9 s between points on one slide,
        // 1.2–1.6 s at a slide or chapter change.
        segments: [
            ['k_intro1', 0.8], ['k_intro2', 0.9], ['k_intro3', 1.6],
            ['k_win1', 0.9], ['k_win2', 0.9], ['k_win3', 1.2], ['k_win4', 0.5], ['k_win5', 0.7], ['k_win6', 0.7], ['k_win7', 1.3],
            ['k_win9', 1.2], ['k_win10', 1.6],
            ['k_adv1', 0.7], ['k_adv2', 1.3],
            ['k_be02_1', 0.7], ['k_be02_2', 0.9], ['k_be02_3', 0.9], ['k_be02_4', 1.3], ['k_be02_5', 0.7], ['k_be02_6', 0.9], ['k_be02_7', 0.9], ['k_be02_8', 1.6],
            ['k_be03_1', 0.7], ['k_be03_2', 0.8], ['k_be03_3', 1.6],
            ['k_be01_1', 0.7], ['k_be01_2', 1.6],
            ['k_q1', 0.7], ['k_q2', 1.6],
            ['k_mkt1', 0.7], ['k_mkt2', 0.9], ['k_mkt3', 0.8], ['k_mkt4', 1.2], ['k_mkt5', 1.6],
            ['k_oct0', 0.8], ['k_oct1', 0.8], ['k_oct2', 1.2], ['k_oct3', 0.5], ['k_oct4', 0.8], ['k_oct5', 0.8], ['k_oct6', 0.9], ['k_oct7', 1.3],
            ['k_fc1', 0.9], ['k_win11', 1.6],
            ['k_out1', 0.9], ['k_out2', 3.5],
        ],
    },
    kotia: {
        title: 'Kolme kotia · talven sähkölasku',
        voice: 'fi-FI-Harper:MAI-Voice-2.1',
        music: 'audio/music/homes-b.mp3',
        musicDb: -4,
        lead: 0.6,
        segments: [
            ['h_open1', 0.6], ['h_open2', 1.2],
            ['h_ctx1', 0.4], ['h_ctx2', 0.5], ['h_ctx3', 0.6], ['h_ctx4', 1.2],
            ['h_be02_0', 0.4], ['k_be02_1', 0.4], ['k_be02_2', 0.6], ['k_be02_3', 0.6], ['k_be02_4', 0.9], ['k_be02_5', 0.4], ['k_be02_6', 0.6], ['k_be02_7', 0.6], ['k_be02_8', 1.4],
            ['k_be03_1', 0.4], ['k_be03_2', 0.5], ['k_be03_3', 1.4],
            ['h_be01_0', 0.3], ['k_be01_1', 0.4], ['k_be01_2', 1.4],
            ['h_q0', 0.3], ['k_q1', 0.4], ['k_q2', 1.4],
            ['h_note', 0.5], ['h_out1', 3.5],
        ],
    },
    numerot: {
        title: 'Lokakuu 18 numerossa',
        voice: 'fi-FI-Harper:MAI-Voice-2.1',
        music: 'audio/music/numbers.mp3',
        musicDb: -2,
        lead: 0.8,
        segments: [
            ['n_open', 1.0],
            ['n_1', 1.0], ['n_2', 1.0], ['n_3', 1.0], ['n_4', 1.0], ['n_5', 1.0], ['n_oct30', 1.4],
            ['n_6', 1.0], ['n_7', 1.0], ['n_8', 1.0], ['n_9', 1.0], ['n_10', 1.4],
            ['n_11', 1.0], ['n_12', 1.0], ['n_flex', 1.0], ['n_apt', 1.0], ['n_13', 1.4],
            ['n_14', 1.0], ['n_terms', 1.4],
            ['n_sum', 0.8], ['k_out1', 3.5],
        ],
    },
    saa: {
        title: 'Voltikan sähkösää',
        voice: 'fi-FI-Grant:MAI-Voice-2.1',
        music: 'audio/music/weather-b.mp3',
        musicDb: -4,
        lead: 1.0,
        segments: [
            ['s_open1', 1.2],
            ['s_oct1', 0.5], ['s_oct2', 0.6], ['s_oct3', 1.0],
            ['s_map0', 0.5], ['s_map1', 0.6], ['s_map2', 0.6], ['s_map3', 1.2],
            ['s_fc0', 0.5], ['s_fc1', 0.6], ['s_fc2', 0.6], ['s_fc3', 1.2],
            ['s_warn0', 0.5], ['s_warn1', 0.6], ['s_warn2', 1.2],
            ['s_mkt0', 0.5], ['s_mkt1', 0.6], ['s_mkt2', 1.2],
            ['s_adv0', 0.6], ['s_adv1', 0.8], ['s_adv2', 0.8], ['s_adv3', 0.8], ['s_adv4', 1.2],
            ['s_out1', 3.5],
        ],
    },
};

// Folder of the voice takes of a variant: audio/vo/<this>/. MAI: voice name (+ speed); other models: `vo`.
export const voDir = (v) => `audio/vo/${v.vo ?? v.voice.split(':')[0].replace('fi-FI-', '').toLowerCase() + (v.speed ? '-' + v.speed : '')}`;

// Subtitle text where the spoken form differs from the written form (timeline.mjs uses it for the .srt).
export const SUBS = {
    k_win3: 'Odotamme, että pörssisähkö maksaa marraskuusta maaliskuuhun 11–16 senttiä.',
    k_be02_6: 'Edullisin on Pohjois-Karjalan Sähkön Optimi takuu. Ensimmäinen vuosi maksaa noin 1 500 euroa. Edullisin pörssisähkö, Herrforsin Spot+, maksaa noin 1 560 euroa.',
    k_out1: 'Laske sopimusten hinnat omalla kulutuksellasi Voltikassa: voltikka.fi.',
    n_1: '4,77. Senttiä kilowattitunnilta. Sen verran pörssisähkö maksoi lokakuussa.',
    s_fc1: 'Talvi on tulossa, ja sähkö kallistuu. Marraskuun futuuri on 11,3 senttiä kilowattitunnilta. Tammikuusta maaliskuuhun futuuri on 14,5 senttiä.',
    n_open: 'Lokakuun sähkömarkkina 18 numerossa.',
    // Numbers in a case other than the nominative are written in words in the voice (Harper said "kaksikymmentäneljä kuukauden").
    k_be02_5: 'Jos haluat kiinteän hinnan, valitse 24 kuukautta. Se kattaa kaksi talvea, ja se on halvempi kuin 12 kuukauden sopimus.',
    k_q2: 'Sähkölämmitteisessä pientalossa tammikuusta maaliskuuhun maksaa silloin noin 155 euroa enemmän kuin nyt. Vaihda ennen vuodenvaihdetta pörssisähköön tai 24 kuukauden kiinteään hintaan.',
    k_be03_3: 'Valitse pörssisähkö tai edullisin pitkä kiinteä hinta. Keskihintainen 12 kuukauden sopimus maksaa noin 65 euroa enemmän.',
};
