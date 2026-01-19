KYMPPIVOIMA.laskuri.collectValues = function() {

    'use strict';

    /*var laskuri 						= KYMPPIVOIMA.laskuri,
		calculationResults	= laskuri.calculate(laskuri);*/

    /*KYMPPIVOIMA.laskuri.showResults(calculationResults);
	return true;*/
    KYMPPIVOIMA.laskuri.calculationdata.results = KYMPPIVOIMA.laskuri.calculationdata.reset();

    var perustiedot = KYMPPIVOIMA.laskuri.calculationdata.values.perustiedot;
    delete (perustiedot.asumismuoto);
    delete (perustiedot.henkilomaara);
    delete (perustiedot.pinta_ala);
    delete (perustiedot.puolilampimat);
    delete (perustiedot.rakennusvuosi);
    delete (perustiedot.peruskorjausvuosi);
    delete (perustiedot.lattialammitys);

    var lammitys = KYMPPIVOIMA.laskuri.calculationdata.values.lammitys;
    delete (lammitys.lammitys_kuuluu_yhtiolle);
    delete (lammitys.lisa_ilmalampopumppu);
    delete (lammitys.lisa_puu);
    delete (lammitys.puun_kulutus);
    delete (lammitys.tulisija);
    delete (lammitys.lisa_sahko);
    delete (lammitys.lattialammitys_pinta_ala);
    delete (lammitys.lampopumppu);

    var kayttovesi = KYMPPIVOIMA.laskuri.calculationdata.values.kayttovesi;
    delete (kayttovesi.vesi_kuuluu_yhtiolle);
    delete (kayttovesi.veden_lammitys);
    delete (kayttovesi.veden_kaytto);
    delete (kayttovesi.veden_lampopumppu);

    var kotitalous = KYMPPIVOIMA.laskuri.calculationdata.values.kotitalous;
    delete (kotitalous.jaakaapit);
    delete (kotitalous.astianpesukone);
    delete (kotitalous.kuivausrumpu);
    delete (kotitalous.viihde);
    delete (kotitalous.valaistus);
    delete (kotitalous.auto);
    delete (kotitalous.ruoka);
    delete (kotitalous.pyykinpesu);
    delete (kotitalous.sauna);
    delete (kotitalous.saunaHetivalmis);
    delete (kotitalous.virtojen_katkaisu);
    delete (kotitalous.energiansaastolamput);

    /*
	*
	*   )
	*  (
	*   )
	*  (
	*   )
	*  (
	*   )
	*  \|/
	*   V
	*
	*/

    /**
	* Perustiedot
	*
	*/

    var perustiedotGroup = $('#perustiedot .input-group.selected')
      , perustiedotGroupKey = $(perustiedotGroup).get(0).id
      , perustiedot = KYMPPIVOIMA.laskuri.calculationdata.values.perustiedot;

    /**
	* Paritalo
	*
	*/

    if (perustiedotGroupKey === 'paritalo') {
        perustiedot.asumismuoto = 1;
        perustiedot.henkilomaara = $(perustiedotGroup).find('#paritalo-henkilomaara').val();
        perustiedot.pinta_ala = Number($(perustiedotGroup).find('#paritalo-pinta-ala').val());
        perustiedot.rakennusvuosi = $(perustiedotGroup).find('#paritalo-rakennusvuosi').val();
        perustiedot.puolilampimat = Number($(perustiedotGroup).find('#paritalo-puolilampimien_pinta-ala').val());
        perustiedot.peruskorjausvuosi = $(perustiedotGroup).find('#paritalo-peruskorjausvuosi').val();
    }

    /**
	* Rivitalo
	*
	*/

    if (perustiedotGroupKey === 'rivitalo') {
        perustiedot.asumismuoto = 2;
        perustiedot.henkilomaara = $(perustiedotGroup).find('#rivitalo-henkilomaara').val();
        perustiedot.pinta_ala = Number($(perustiedotGroup).find('#rivitalo-pinta-ala').val());
        perustiedot.rakennusvuosi = $(perustiedotGroup).find('#rivitalo-rakennusvuosi').val();
        perustiedot.puolilampimat = Number($(perustiedotGroup).find('#rivitalo-puolilampimien_pinta-ala').val());
        perustiedot.peruskorjausvuosi = $(perustiedotGroup).find('#rivitalo-peruskorjausvuosi').val();
    }

    /**
	* Kerrostalo
	*
	*/

    if (perustiedotGroupKey === 'kerrostalo') {

        perustiedot.asumismuoto = 3;
        perustiedot.henkilomaara = $(perustiedotGroup).find('#kerrostalo-henkilomaara').val();
        perustiedot.pinta_ala = $(perustiedotGroup).find('#kerrostalo-pinta-ala').val();
        perustiedot.lattialammitys = $(perustiedotGroup).find('#kerrostalo-lattialammitys').get(0).checked;

        if (perustiedot.lattialammitys === true) {
            perustiedot.puolilampimat = $(perustiedotGroup).find('#kerrostalo-lattialammitys-ala').val();
        }

    }

    /*
	*
	*   )
	*  (
	*   )
	*  (
	*   )
	*  (
	*   )
	*  \|/
	*   V
	*
	*/

    if (perustiedotGroupKey !== 'kerrostalo') {

        /**
		* Asunnon lämmitys
		*
		*/

        var lammitysGroup = $('#lammitys .input-group.selected')
          , lammitysGroupKey = $(lammitysGroup).get(0).id
          , lammitys = KYMPPIVOIMA.laskuri.calculationdata.values.lammitys;

        lammitys.lammitys_kuuluu_yhtiolle = false;

        /**
		* Sähkö
		*
		*/

        if (lammitysGroupKey === 'sahko') {

            lammitys.lammitys = 0;
            lammitys.lisa_ilmalampopumppu = $(lammitysGroup).find('#lisalammitys-pumppu').get(0).checked;
            lammitys.lisa_puu = $(lammitysGroup).find('#lisalammitys-puu').get(0).checked;
            lammitys.lattialammitys_pinta_ala = 0;

            /*if (lammitys.lisa_ilmalampopumppu === true) {

			}*/

            if (lammitys.lisa_puu === true) {
                lammitys.puun_kulutus = $(lammitysGroup).find('#polttopuu-kulutus').val();
                lammitys.tulisija = $(lammitysGroup).find('#sahko-tulisijatyyppi').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;

                lammitys.lisa_sahko = $(lammitysGroup).find('#lisalattialammitys-sahko').get(0).checked;
                if (lammitys.lisa_sahko === true) {
                    lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
                }
            }

        }

        /**
		* Kaukolämpö
		*
		*/

        if (lammitysGroupKey === 'kaukolampo') {

            lammitys.lammitys = 1;
            lammitys.lisa_sahko = $(lammitysGroup).find('#lattialammitys-sahko').get(0).checked;

            if (lammitys.lisa_sahko === true) {
                lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;
            }

        }

        /**
		* Öljy
		*
		*/

        if (lammitysGroupKey === 'oljy') {

            lammitys.lammitys = 2;
            lammitys.lisa_sahko = $(lammitysGroup).find('#lattialammitys-sahko').get(0).checked;

            if (lammitys.lisa_sahko === true) {
                lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;
            }

        }

        /**
		* Lämpöpumppu
		*
		*/

        if (lammitysGroupKey === 'lampopumppu') {

            lammitys.lammitys = 3;
            lammitys.lampopumppu = parseInt($(lammitysGroup).find('#lampopumppu-lampopumpputyyppi').val());
            lammitys.lisa_sahko = $(lammitysGroup).find('#lattialammitys-sahko').get(0).checked;
            lammitys.lisa_puu = $(lammitysGroup).find('#lisalammitys-puu').get(0).checked;

            if (lammitys.lisa_sahko === true) {
                lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
            }

            if (lammitys.lisa_puu === true) {
                lammitys.puun_kulutus = $(lammitysGroup).find('#polttopuu-kulutus').val();
                lammitys.tulisija = $(lammitysGroup).find('#lampopumppu-tulisijatyyppi').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;
            }

        }

        /**
		* Puu
		*
		*/

        if (lammitysGroupKey === 'puu') {

            lammitys.lammitys = 4;
            lammitys.lisa_sahko = $(lammitysGroup).find('#lattialammitys-sahko').get(0).checked;

            if (lammitys.lisa_sahko === true) {
                lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;
            }

        }

        /**
		* Pelletit
		*
		*/

        if (lammitysGroupKey === 'pelletit') {

            lammitys.lammitys = 5;
            lammitys.lisa_sahko = $(lammitysGroup).find('#lattialammitys-sahko').get(0).checked;

            if (lammitys.lisa_sahko === true) {
                lammitys.lattialammitys_pinta_ala = $(lammitysGroup).find('#lattialammitys-ala').val();
            }

            if (perustiedotGroupKey === 'rivitalo' && $(lammitysGroup).find('#lammitys-taloyhtio').get(0).checked === true) {
                lammitys.lammitys_kuuluu_yhtiolle = true;
            }

        }

        /*
		*
		*   )
		*  (
		*   )
		*  (
		*   )
		*  (
		*   )
		*  \|/
		*   V
		*
		*/

        /**
		* Käyttöveden lämmitys
		*
		*/

        var kayttovesiGroup = $('#vesi .input-group.selected')
          , kayttovesiGroupKey = $(kayttovesiGroup).get(0).id
          , kayttovesi = KYMPPIVOIMA.laskuri.calculationdata.values.kayttovesi;

        kayttovesi.vesi_kuuluu_yhtiolle = false;

        /**
		* Sähkö
		*
		*/

        if (kayttovesiGroupKey === 'sahko') {
            kayttovesi.veden_lammitys = 0;
            kayttovesi.veden_kaytto = Number($(kayttovesiGroup).find('#vedenkaytto').val());

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

        /**
		* Kaukolämpö
		*
		*/

        if (kayttovesiGroupKey === 'kaukolampo') {
            kayttovesi.veden_lammitys = 1;

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

        /**
		* Öljy
		*
		*/

        if (kayttovesiGroupKey === 'oljy') {
            kayttovesi.veden_lammitys = 2;

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

        /**
		* Lämpöpumppu
		*
		*/

        if (kayttovesiGroupKey === 'lampopumppu') {
            kayttovesi.veden_lammitys = 3;
            kayttovesi.veden_lampopumppu = $(kayttovesiGroup).find('#lampopumppu-tyyppi').val();
            kayttovesi.veden_kaytto = Number($(kayttovesiGroup).find('#vedenkaytto').val());

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

        /**
		* Puu
		*
		*/

        if (kayttovesiGroupKey === 'puu') {
            kayttovesi.veden_lammitys = 4;

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

        /**
		* Pelletit
		*
		*/

        if (kayttovesiGroupKey === 'pelletit') {
            kayttovesi.veden_lammitys = 5;

            if (perustiedotGroupKey === 'rivitalo' && $(kayttovesiGroup).find('#kayttovesi-lammitys-taloyhtio').get(0).checked === true) {
                kayttovesi.vesi_kuuluu_yhtiolle = true;
            }
        }

    }
    /*
	*
	*   )
	*  (
	*   )
	*  (
	*   )
	*  (
	*   )
	*  \|/
	*   V
	*
	*/

    /**
	* Kotitaloussähkö
	*
	*/

    var kotitaloussahkoGroup = $('#kotitaloussahko')
      , kotitaloussahko = KYMPPIVOIMA.laskuri.calculationdata.values.kotitalous;

    // Sivu 1
    kotitaloussahko.jaakaapit = $(kotitaloussahkoGroup).find('#sahko-kylmalaitteet').val();
    kotitaloussahko.astianpesukone = $(kotitaloussahkoGroup).find('#sahko-astianpesukone').val();
    kotitaloussahko.pyykinpesu = $(kotitaloussahkoGroup).find('#sahko-pyykinpesukone').val();
    kotitaloussahko.sauna = $(kotitaloussahkoGroup).find('#sahko-sauna').val();
    kotitaloussahko.ruoka = $(kotitaloussahkoGroup).find('#sahko-ruoka').val();
    kotitaloussahko.kuivausrumpu = $(kotitaloussahkoGroup).find('#sahko-kuivausrumpu').val();
    kotitaloussahko.auto = $(kotitaloussahkoGroup).find('#sahko-auto').val();

    // Sivu 2
    kotitaloussahko.viihde = $(kotitaloussahkoGroup).find('input[name=sahko-viihdetekniikka]:checked').val();
    kotitaloussahko.valaistus = $(kotitaloussahkoGroup).find('input[name=sahko-valaistus]:checked').val();
    kotitaloussahko.virtojen_katkaisu = $(kotitaloussahkoGroup).find('input[name=sahko-katkaisin]:checked').val();
    kotitaloussahko.energiansaastolamput = $(kotitaloussahkoGroup).find('input[name=sahko-lamput]:checked').val();
    // kotitaloussahko.saunaHetivalmis				= $(kayttovesiGroup).find('#').val(); // TODO: Missä tämä on?

    // set default values
    if (typeof kotitaloussahko.viihde === 'undefined') {
        kotitaloussahko.viihde = 0;
    }
    if (typeof kotitaloussahko.valaistus === 'undefined') {
        kotitaloussahko.valaistus = 0;
    }
    if (typeof kotitaloussahko.virtojen_katkaisu === 'undefined') {
        kotitaloussahko.virtojen_katkaisu = 0;
    }
    if (typeof kotitaloussahko.energiansaastolamput === 'undefined') {
        kotitaloussahko.energiansaastolamput = 0;
    }

    /**
	* Start calculation
	*
	*/

    var laskuri = KYMPPIVOIMA.laskuri
      , calculationResults = laskuri.calculate(laskuri);

    /**
	* Place results
	*
	*/

    KYMPPIVOIMA.laskuri.showResults(calculationResults);

    /**
	* Save results
	*
	*/
    var postdata = {
        values: $.extend({}, laskuri.calculationdata.values),
        details: $.extend({}, laskuri.calculationdata.results.details)
    };

    postdata.details.details_lammitys = postdata.details.lammitys;
    delete postdata.details.lammitys;
    delete postdata.details.pdfData;

    $.post("save", postdata);
}
;

KYMPPIVOIMA.laskuri.constants.vuotuinenVedenkulutus = 50;

/* lämmin käyttövesi kertoimet */
KYMPPIVOIMA.laskuri.constants.poistoilmaKerroin = 1.5;
KYMPPIVOIMA.laskuri.constants.ilmavesiKerroin = 2;
KYMPPIVOIMA.laskuri.constants.maalampoKerroin = 2.0;

KYMPPIVOIMA.laskuri.types.lamminkayttovesiType = {
    sahko: 0,
    kaukolampo: 1,
    oljy: 2,
    lampopumppu: 3,
    puu: 4,
    pelletti: 5
}

KYMPPIVOIMA.laskuri.types.lammitysType = {
    sahko: 0,
    kaukolampo: 1,
    oljy: 2,
    lampopumppu: 3,
    puukattila: 4,
    pelletti: 5
}

KYMPPIVOIMA.laskuri.types.lampopumppuType = {
    poistoilma: 2,
    ilmavesi: 1,
    maalampo: 0
}

KYMPPIVOIMA.laskuri.types.rakennusvuosiType = {
    alkaen2010: 0,
    alkaen2003: 1,
    alkaen1980: 2,
    alkaen1970: 3,
    alkaen1960: 4,
    ennen1960: 5
}

KYMPPIVOIMA.laskuri.types.rakennusvuosiKaKerroin = [2010, 2006, 1991, 1975, 1965, 1959];
KYMPPIVOIMA.laskuri.types.peruskorjausvuosiKaKerroin = [0, 2010, 2006, 1991, 1975, 1965, 1959];

KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType = [];
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.ennen1960] = 170;
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.alkaen1960] = 180;
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.alkaen1970] = 140;
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.alkaen1980] = 120;
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.alkaen2003] = 100;
KYMPPIVOIMA.laskuri.types.rakennusvuosiKulutusType[KYMPPIVOIMA.laskuri.types.rakennusvuosiType.alkaen2010] = 65;

KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType = [];
KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType[0] = {
    nimi: 'Moderni, testattu varaava uuni',
    hyotysuhde: 85,
    kerroin: 0.85
};
KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType[1] = {
    nimi: 'Perinteinen varaava uuni',
    hyotysuhde: 75,
    kerroin: 0.75
};
KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType[2] = {
    nimi: 'Kevytrakenteinen uuni',
    hyotysuhde: 50,
    kerroin: 0.50
};
KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType[3] = {
    nimi: 'Liesi',
    hyotysuhde: 50,
    kerroin: 0.50
};
KYMPPIVOIMA.laskuri.types.liesiHyotysuhdeType[4] = {
    nimi: 'avotakka, kiuas',
    hyotysuhde: 25,
    kerroin: 0.25
};

/*KYMPPIVOIMA.laskuri.types.energiansaastolamputType = {
		eiyhtaan	: 0,
		hieman 		: 1,
		puolet 		: 2,
		paljon 		: 3,
		kaikki 		: 4
	}*/
KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiDefault = 1.00;
KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType = [1.50, 1.30, 1.10, 0.90, 0.70, 0.50];
/*KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType[KYMPPIVOIMA.laskuri.types.energiansaastolamputType.eiyhtaan] 	= 1.50;
	KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType[KYMPPIVOIMA.laskuri.types.energiansaastolamputType.hieman] 		= 1.30;
	KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType[KYMPPIVOIMA.laskuri.types.energiansaastolamputType.puolet] 		= 1.00;
	KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType[KYMPPIVOIMA.laskuri.types.energiansaastolamputType.paljon] 		= 0.70;
	KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiType[KYMPPIVOIMA.laskuri.types.energiansaastolamputType.kaikki] 		= 0.50;*/

KYMPPIVOIMA.laskuri.types.asukkaatMaaraType = {
    yksi: 0,
    kaksi: 1,
    kolme: 2,
    nelja: 3,
    viisi: 4,
    kuusi: 5,
    seitseman: 6,
    kahdeksan: 7
}

KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType = [];
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '2h+k',
    min: 20,
    max: 60,
    kulutusAsukasmaarittain: {
        1: 1759,
        2: 2505,
        3: 2788,
        4: 3242,
        5: 3242,
        6: 3242,
        7: 3242,
        8: 3242
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '3h+k',
    min: 61,
    max: 90,
    kulutusAsukasmaarittain: {
        1: 2324,
        2: 3009,
        3: 3429,
        4: 3810,
        5: 4686,
        6: 4686,
        7: 4686,
        8: 4686
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '4h+k',
    min: 91,
    max: 100,
    kulutusAsukasmaarittain: {
        1: 3029,
        2: 3521,
        3: 4039,
        4: 4254,
        5: 4693,
        6: 5026,
        7: 5026,
        8: 5026
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '4h+k',
    min: 101,
    max: 120,
    kulutusAsukasmaarittain: {
        1: 3370,
        2: 3994,
        3: 4501,
        4: 4879,
        5: 5280,
        6: 5662,
        7: 6044,
        8: 6044
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '5h+k',
    min: 121,
    max: 140,
    kulutusAsukasmaarittain: {
        1: 3529,
        2: 4303,
        3: 4963,
        4: 5040,
        5: 5532,
        6: 5933,
        7: 6333,
        8: 6734
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '5h+k',
    min: 141,
    max: 160,
    kulutusAsukasmaarittain: {
        1: 3695,
        2: 4468,
        3: 5000,
        4: 5198,
        5: 5714,
        6: 6118,
        7: 6522,
        8: 6925
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '6h+k',
    min: 161,
    max: 180,
    kulutusAsukasmaarittain: {
        1: 4188,
        2: 5064,
        3: 5667,
        4: 5891,
        5: 6476,
        6: 6934,
        7: 7391,
        8: 7849
    }
});
KYMPPIVOIMA.laskuri.types.asunnonKokoNeliotType.push({
    nimi: '6h+k',
    min: 181,
    max: 200,
    kulutusAsukasmaarittain: {
        1: 4680,
        2: 5659,
        3: 6333,
        4: 6584,
        5: 7238,
        6: 7749,
        7: 8261,
        8: 8772
    }
});

/**
	* Laskuri datamodel for calculation and calculation results
	*
	* KYMPPIVOIMA.laskuri.data
	*
	*/

KYMPPIVOIMA.laskuri.calculationdata = {

    values: {

        perustiedot: {
            asumismuoto: 1,
            henkilomaara: 4,
            // kpl
            pinta_ala: 101,
            // m2
            puolilampimat: 30,
            // m2
            rakennusvuosi: 2,
            peruskorjausvuosi: 5,
            lattialammitys: false,
            // m2
        },

        lammitys: {
            lammitys: 0,
            lammitys_kuuluu_yhtiolle: false,
            lisa_ilmalampopumppu: true,
            lisa_puu: true,
            puun_kulutus: 7,
            // pm3
            tulisija: 1,
            lisa_sahko: false,
            lattialammitys_pinta_ala: 0,
            // m2
            lampopumppu: 0
        },

        kayttovesi: {
            vesi_kuuluu_yhtiolle: false,
            veden_lammitys: 0,
            veden_kaytto: 100,
            // m3
            veden_lampopumppu: 1
        },

        kotitalous: {
            jaakaapit: 3,
            // kpl
            astianpesukone: 4,
            // krt/vko
            kuivausrumpu: 5,
            // krt/vko
            viihde: 2,
            // käyttöaste
            valaistus: 2,
            // käyttöaste
            auto: 7,
            // krt/vko
            ruoka: 10,
            // krt/vko
            pyykinpesu: 5,
            // krt/vko
            sauna: 5,
            // krt/vko
            saunaHetivalmis: false,
            // onko sauna tyyppiä heti valmis
            virtojen_katkaisu: false,
            energiansaastolamput: KYMPPIVOIMA.laskuri.types.energiansaastoprosenttiDefault
        }

    },

    results: {

        totals: {
            vuosikulutus: 0,
            vuosikulutus_vertailuarvo: 0,
            vuosikulutus_erotus: 0,
            hinta: 0,
            hinta_vertailuarvo: 0,
            hinta_erotus: 0
        },

        groups: {
            lammityksenEnergiankulutus: 0,
            lammitysTukimuodotHuomioiden: 0,
            lamminVesi: 0,
            kotitalousSahko: 0,
            kokonaissahkoenergia: 0
        },

        details: {
            lammitys: 0,
            lammitys_vertailuarvo: 0,
            lammitys_erotus: 0,
            autolammitys: 0,
            autolammitys_vertailuarvo: 0,
            autolammitys_erotus: 0,
            kayttovedenlammitys: 0,
            kayttovedenlammitys_vertailuarvo: 0,
            kayttovedenlammitys_erotus: 0,
            kylmasailytys: 0,
            kylmasailytys_vertailuarvo: 0,
            kylmasailytys_erotus: 0,
            vaatehuolto: 0,
            vaatehuolto_vertailuarvo: 0,
            vaatehuolto_erotus: 0,
            saunominen: 0,
            saunominen_vertailuarvo: 0,
            saunominen_erotus: 0,
            viihde: 0,
            viihde_vertailuarvo: 0,
            viihde_erotus: 0,
            valaistus: 0,
            valaistus_vertailuarvo: 0,
            valaistus_erotus: 0
        }

    },

    reset: function() {

        return {

            totals: {
                vuosikulutus: 0,
                vuosikulutus_vertailuarvo: 0,
                vuosikulutus_erotus: 0,
                hinta: 0,
                hinta_vertailuarvo: 0,
                hinta_erotus: 0
            },

            groups: {
                lammityksenEnergiankulutus: 0,
                lammitysTukimuodotHuomioiden: 0,
                lamminVesi: 0,
                kotitalousSahko: 0,
                kokonaissahkoenergia: 0
            },

            details: {
                lammitys: 0,
                lammitys_vertailuarvo: 0,
                lammitys_erotus: 0,
                autolammitys: 0,
                autolammitys_vertailuarvo: 0,
                autolammitys_erotus: 0,
                kayttovedenlammitys: 0,
                kayttovedenlammitys_vertailuarvo: 0,
                kayttovedenlammitys_erotus: 0,
                kylmasailytys: 0,
                kylmasailytys_vertailuarvo: 0,
                kylmasailytys_erotus: 0,
                vaatehuolto: 0,
                vaatehuolto_vertailuarvo: 0,
                vaatehuolto_erotus: 0,
                saunominen: 0,
                saunominen_vertailuarvo: 0,
                saunominen_erotus: 0,
                viihde: 0,
                viihde_vertailuarvo: 0,
                viihde_erotus: 0,
                valaistus: 0,
                valaistus_vertailuarvo: 0,
                valaistus_erotus: 0
            }

        }
    }

}

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculate = function(laskuri) {

    var resultmodel = laskuri.calculationdata.results;

    resultmodel.groups.lammityksenEnergiankulutus = 0;
    resultmodel.groups.lammitysTukimuodotHuomioiden = 0;

    // ei kerrostalo
    if (laskuri.calculationdata.values.perustiedot.asumismuoto !== 3) {

        // 1a & 1b. Lämmitys tukilämpömuodot huomioiden
        var lammitys = laskuri.calculation.lammitys(laskuri);

        resultmodel.groups.lammityksenEnergiankulutus = lammitys.lammityksenEnergiankulutus;
        resultmodel.groups.lammitysTukimuodotHuomioiden = lammitys.lammitysTukilammitysmuodotHuomioiden;

        // 2. Lämmin käyttövesi
        resultmodel.groups.lamminVesi = laskuri.calculation.lamminKayttovesi(laskuri);

    } else {
        // Kerrostalon lämmitys = lisälattialämmitys
        if (laskuri.calculationdata.values.perustiedot.lattialammitys === true) {
            var lisalattialammitys = laskuri.calculationdata.values.perustiedot.puolilampimat * 200;
            resultmodel.groups.lammityksenEnergiankulutus = lisalattialammitys;
            resultmodel.groups.lammitysTukimuodotHuomioiden = lisalattialammitys;
        }
    }

    // 3. Kotitaloussähkö
    var kotitaloussahkoResults = laskuri.calculation.kotitaloussahko(laskuri, true);

    resultmodel.groups.kotitalousSahko = 0;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.jaakaappi;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.astianpesu;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.liesi;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.pyykinpesu;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.pyykinkuivaaja;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.auto;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.viihde;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.standby;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.valaistus;
    resultmodel.groups.kotitalousSahko += kotitaloussahkoResults.sauna;

    // Kokonaissähköenergia
    resultmodel.groups.kokonaissahkoenergia = resultmodel.groups.lammitysTukimuodotHuomioiden + resultmodel.groups.lamminVesi + resultmodel.groups.kotitalousSahko;

    // Yhteenveto
    laskuri = laskuri.calculation.yhteenveto(laskuri);

    /* debug */
    /*console.log('1b. Lammitys lammitysTukimuodotHuomioiden: '	+ Math.round(resultmodel.groups.lammitysTukimuodotHuomioiden)	+ ' / 6692');
			console.log('2. Lammin käyttövesi: '											+ Math.round(resultmodel.groups.lamminVesi)										+ ' / 6000');
			console.log('3. Kotitaloussähkö: '												+ Math.round(resultmodel.groups.kotitalousSahko)							+ ' / 8010');
			console.log('= Kokonaissähköenergia: '										+ Math.round(resultmodel.groups.kokonaissahkoenergia)					+ ' / 20702');*/

    return laskuri.calculationdata.results.details;

}

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculation.helpers = {};

KYMPPIVOIMA.laskuri.calculation.helpers.pesutilaLammitys = function(datamodel, lisays) {
    var output = lisays + (datamodel.lattialammitys_pinta_ala * 200);
    return output;
}

KYMPPIVOIMA.laskuri.calculation.helpers.lammonVuosikerroin = function(laskuri) {
    var ka, rakennusvuosiKulutus;

    if (laskuri.calculationdata.values.perustiedot.peruskorjausvuosi != 0) {
        ka = KYMPPIVOIMA.laskuri.calculation.helpers.getAverage([KYMPPIVOIMA.laskuri.types.rakennusvuosiKaKerroin[laskuri.calculationdata.values.perustiedot.rakennusvuosi], KYMPPIVOIMA.laskuri.types.peruskorjausvuosiKaKerroin[laskuri.calculationdata.values.perustiedot.peruskorjausvuosi]]);

        if (ka < 1960) {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[5];
        } else if (ka < 1970) {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[4];
        } else if (ka < 1980) {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[3];
        } else if (ka < 2003) {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[2];
        } else if (ka < 2010) {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[1];
        } else {
            rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[0];
        }

    } else {
        rakennusvuosiKulutus = laskuri.types.rakennusvuosiKulutusType[laskuri.calculationdata.values.perustiedot.rakennusvuosi];
    }

    return rakennusvuosiKulutus;
}

KYMPPIVOIMA.laskuri.calculation.helpers.sahkonkulutus = function(laskuri) {
    var asunnonKokoNeliotType = laskuri.types.asunnonKokoNeliotType
      , perustiedot = laskuri.calculationdata.values.perustiedot
      , syotettyNeliomaara = perustiedot.pinta_ala
      , syotettyHenkilomaara = perustiedot.henkilomaara
      , output = 0
      , item = 0
      , len = asunnonKokoNeliotType.length;

    for (item = 0; item < len; item++) {
        var current = asunnonKokoNeliotType[item];
        if (syotettyNeliomaara >= current.min && syotettyNeliomaara <= current.max) {
            var vuotuinenSahkonKulutus = current.kulutusAsukasmaarittain[syotettyHenkilomaara];
            output = vuotuinenSahkonKulutus;
        }
    }
    return output;
}

KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage = function(val, max) {
    return Math.round((val / max) * 100);
}
;

KYMPPIVOIMA.laskuri.calculation.helpers.getAverage = function(values) {
    var total = 0, i, len = values.length;

    for (i = 0; i < len; i++) {
        total += values[i];
    }

    return Math.round(total / len);
}
;

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculation.lammitys = function(laskuri) {

    var datamodel = laskuri.calculationdata.values.lammitys
      , perustiedot = laskuri.calculationdata.values.perustiedot
      , constants = laskuri.constants
      , helpers = laskuri.calculation.helpers
      , lammitysType = laskuri.types.lammitysType
      , lampopumppuType = laskuri.types.lampopumppuType
      , liesiHyotysuhdeType = laskuri.types.liesiHyotysuhdeType
      , total = {
        lammityksenEnergiankulutus: 0,
        lammitysTukilammitysmuodotHuomioiden: 0
    };

    // 1 a. Lämmityksen energiankulutus

    //if( datamodel.lammitys_kuuluu_yhtiolle === false ) {

    /**
            * Sähkö
            *
            */

    if (datamodel.lammitys === lammitysType.sahko) {
        // kaava: (asuinneliöt + 0.5 * puolilämpimät tilat) * energiankulutus asunnon vuoden mukaan
        if (perustiedot.asumismuoto === 1 || (perustiedot.asumismuoto === 2 && datamodel.lammitys_kuuluu_yhtiolle === false)) {
            var _vuosikerroin = helpers.lammonVuosikerroin(laskuri)
              , _sahko = (perustiedot.pinta_ala + (0.5 * perustiedot.puolilampimat)) * _vuosikerroin;
            total.lammityksenEnergiankulutus = _sahko;
        } else if (perustiedot.asumismuoto === 2 && datamodel.lammitys_kuuluu_yhtiolle === true) {
            if (datamodel.lisa_sahko) {
                total.lammityksenEnergiankulutus = datamodel.lattialammitys_pinta_ala * 200;
            }
        }
    }

    /**
            * Kaukolämpö
            *
            */

    if (datamodel.lammitys === lammitysType.kaukolampo) {
        total.lammityksenEnergiankulutus = 850;
        if (datamodel.lisa_sahko) {
            total.lammityksenEnergiankulutus = helpers.pesutilaLammitys(datamodel, 850);
        }
    }

    /**
            * Öljy
            *
            */

    if (datamodel.lammitys === lammitysType.oljy) {
        total.lammityksenEnergiankulutus = 700;
        if (datamodel.lisa_sahko) {
            total.lammityksenEnergiankulutus = helpers.pesutilaLammitys(datamodel, 700);
        }
    }

    /**
            * Lämpöpumppu
            *
            */

    if (datamodel.lammitys === lammitysType.lampopumppu) {

        if (perustiedot.asumismuoto === 1) {
            var _vuosikerroin = helpers.lammonVuosikerroin(laskuri);
            total.lammityksenEnergiankulutus = (perustiedot.pinta_ala + 0.5 * perustiedot.puolilampimat) * _vuosikerroin;
        }
        if (datamodel.lisa_sahko) {
            total.lammityksenEnergiankulutus += datamodel.lattialammitys_pinta_ala * 200;
        }
    }

    /**
            * Puukattila
            *
            */

    if (datamodel.lammitys === lammitysType.puukattila) {
        total.lammityksenEnergiankulutus = 400;
        if (datamodel.lisa_sahko) {
            total.lammityksenEnergiankulutus = helpers.pesutilaLammitys(datamodel, 400);
        }
    }

    /**
            * Pelletti
            *
            */

    if (datamodel.lammitys === lammitysType.pelletti) {
        total.lammityksenEnergiankulutus = 1500;
        if (datamodel.lisa_sahko) {
            total.lammityksenEnergiankulutus = helpers.pesutilaLammitys(datamodel, 1500);
        }
    }

    // 1 b. Lämmitys tukilämmitysmuodot huomioiden

    /**
            * Puu sekä ilmalämpöpumppu on valittu
            *
            */

    if ((datamodel.lammitys === lammitysType.sahko && datamodel.lisa_puu === true) || (datamodel.lammitys === lammitysType.lampopumppu && datamodel.lisa_puu === true)) {

        var _puusaasto = datamodel.puun_kulutus * 1500 * liesiHyotysuhdeType[datamodel.tulisija].kerroin
          , _lammitysPuusaastolla = total.lammityksenEnergiankulutus - _puusaasto;

        if (_lammitysPuusaastolla > 0) {
            total.lammitysTukilammitysmuodotHuomioiden = _lammitysPuusaastolla;
        } else {
            total.lammitysTukilammitysmuodotHuomioiden = 0;
        }

    } else {
        total.lammitysTukilammitysmuodotHuomioiden = total.lammityksenEnergiankulutus;
    }

    // 27.01.2015 kerrotaan mlp,pilp tai ilve cop-kertoimella kokonaisenergiankulutus josta vähennetty tukilämmitysmuodot. cop-kertoimet päivitetty.
    if (datamodel.lammitys === lammitysType.lampopumppu) {
        if (datamodel.lampopumppu === lampopumppuType.poistoilma) {
            total.lammitysTukilammitysmuodotHuomioiden = total.lammitysTukilammitysmuodotHuomioiden * (1 / 1.5);
        }
        if (datamodel.lampopumppu === lampopumppuType.ilmavesi) {
            total.lammitysTukilammitysmuodotHuomioiden = total.lammitysTukilammitysmuodotHuomioiden * (1 / 2);
        }
        if (datamodel.lampopumppu === lampopumppuType.maalampo) {
            total.lammitysTukilammitysmuodotHuomioiden = total.lammitysTukilammitysmuodotHuomioiden * (1 / 2.7);
        }
    }

    if (datamodel.lammitys === lammitysType.sahko && datamodel.lisa_ilmalampopumppu === true) {
        total.lammitysTukilammitysmuodotHuomioiden = total.lammitysTukilammitysmuodotHuomioiden / 1.25;
    }

    //}

    return total;
}

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculation.lamminKayttovesi = function(laskuri) {

    var lamminkayttovesiType = laskuri.types.lamminkayttovesiType
      , lampopumppuType = laskuri.types.lampopumppuType
      , constants = laskuri.constants
      , datamodel = laskuri.calculationdata.values.kayttovesi
      , perustiedot = laskuri.calculationdata.values.perustiedot
      , total = 0
      , output = 0;

    if (datamodel.vesi_kuuluu_yhtiolle === false) {

        /**
            * Sähkö
            *
            */
        if (datamodel.veden_lammitys === lamminkayttovesiType.sahko) {
            // kaava: henkilömäärä * vuotuinen vedenkulutus (50 m3 ellei tarkempaa arvoa)
            /*var vuotuinenVedenkulutus = constants.vuotuinenVedenkulutus;
                total = perustiedot.henkilomaara * vuotuinenVedenkulutus * 30;*/
            total = datamodel.veden_kaytto * 30;
        }

        /**
            * Kaukolämpö
            *
            */

        if (datamodel.veden_lammitys === lamminkayttovesiType.kaukolampo) {// Ei vaikuta laskentaan
        }

        /**
            * Öljy
            *
            */

        if (datamodel.veden_lammitys === lamminkayttovesiType.oljy) {// Ei vaikuta laskentaan
        }

        /**
            * Lämpöpumppu
            *
            */

        if (datamodel.veden_lammitys === lamminkayttovesiType.lampopumppu) {

            // valitaan laskukaava lämpöpumpun tyypin mukaan
            if (datamodel.veden_lampopumppu == lampopumppuType.poistoilma) {
                total = datamodel.veden_kaytto * 30 / constants.poistoilmaKerroin;
            }
            if (datamodel.veden_lampopumppu == lampopumppuType.ilmavesi) {
                total = datamodel.veden_kaytto * 30 / constants.ilmavesiKerroin;
            }
            if (datamodel.veden_lampopumppu == lampopumppuType.maalampo) {
                total = datamodel.veden_kaytto * 30 / constants.maalampoKerroin;
            }

        }

        /**
            * Puu
            *
            */

        if (datamodel.veden_lammitys === lamminkayttovesiType.puu) {// Ei vaikuta laskentaan
        }

        /**
            * Pelletti
            *
            */

        if (datamodel.veden_lammitys === lamminkayttovesiType.pelletti) {// Ei vaikuta laskentaan
        }

    }

    output = total;

    return output;
}

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculation.kotitaloussahko = function(laskuri, returnObject) {

    var datamodel = laskuri.calculationdata.values.kotitalous
      , helpers = laskuri.calculation.helpers
      , perustiedot = laskuri.calculationdata.values.perustiedot
      , energiansaastoprosentti = laskuri.types.energiansaastoprosenttiType
      , results = laskuri.calculationdata.results
      , total = 0
      , sahkonkulutus = helpers.sahkonkulutus(laskuri)
      , /*_pesutilalammitys 			= helpers.pesutilaLammitys(laskuri.calculationdata.values.lammitys, 0),*/
    _lamminVesi = results.groups.lamminVesi
      , output = 0;

    var totals = {
        jaakaappi: 0,
        astianpesu: 0,
        liesi: 0,
        pyykinpesu: 0,
        pyykinkuivaaja: 0,
        viihde: 0,
        sauna: 0,
        auto: 0,
        valaistus: 0,
        standby: 0,
        total: 0,
        vertailu: {
            totals: {}
        }
    }

    /**
		* lämmitys
		*
		*/
    /*totals.lammitys 					= results.groups.lammityksenEnergiankulutus + _pesutilalammitys - results.groups.lammitysTukimuodotHuomioiden;
		totals.vertailu.lammitys	= results.groups.lammityksenEnergiankulutus;*/
    totals.lammitys = results.groups.lammitysTukimuodotHuomioiden;
    /* + _pesutilalammitys;*/
    totals.vertailu.lammitys = results.groups.lammityksenEnergiankulutus;
    // TODO: onko exelissä vertailuarvo oikea, lämmityksen energiakulutus

    /**
		* standby
		*
		*/
    if (datamodel.virtojen_katkaisu != 0) {
        totals.standby = sahkonkulutus * 0.075;
    }

    /**
		* sähkölaitteet
		*
		*/

    totals.jaakaappi = datamodel.jaakaapit * 350;
    totals.astianpesu = datamodel.astianpesukone * 104;
    totals.liesi = datamodel.ruoka * 36;
    totals.pyykinpesu = datamodel.pyykinpesu * 67.6;
    totals.pyykinkuivaaja = datamodel.kuivausrumpu * 182;
    totals.auto = datamodel.auto * 44;
    totals.viihde = datamodel.viihde * 91.25;

    /**
		* Kylmäsäilytys ja ruuanlaitto
		*
		*/

    totals.kylmasailytys = totals.jaakaappi + totals.astianpesu + totals.liesi;

    /**
		* Vaatehuolto
		*
		*/

    totals.vaatehuolto = totals.pyykinpesu + totals.pyykinkuivaaja;

    /**
		* Vertailuarvoja
		*
		*/

    totals.vertailu.jaakaappi = 2 * 350;
    totals.vertailu.astianpesu = 5 * 104;
    totals.vertailu.liesi = 7 * 36;
    totals.vertailu.pyykinpesu = 5 * 67.6;
    totals.vertailu.pyykinkuivaaja = 4 * 182;
    totals.vertailu.auto = 5 * 44;
    totals.vertailu.lamminVesi = perustiedot.henkilomaara * 45 + results.groups.lamminVesi;
    totals.vertailu.viihde = (totals.viihde / 66 * 50) + totals.standby;

    // totals.vertailu.total 							= {};
    totals.vertailu.kylmasailytys = totals.vertailu.jaakaappi + totals.vertailu.astianpesu + totals.vertailu.liesi;
    totals.vertailu.vaatehuolto = totals.vertailu.pyykinkuivaaja + totals.vertailu.pyykinpesu;

    /**
		* Sauna
		*
		*/

    if (datamodel.saunaHetivalmis === true) {
        totals.sauna = 5 * 52 * 1.5 * 6;
        totals.vertailu.sauna = 5 * 52 * 1.5 * 6;
    } else {
        totals.sauna = datamodel.sauna * 572;
        totals.vertailu.sauna = 2 * 572;
    }

    /**
		* valaistus
		*
		*/

    var _valaistus = (sahkonkulutus * 0.25) / 2 * datamodel.valaistus
      , _energiasaastovalinta = datamodel.energiansaastolamput;

    totals.valaistus = _valaistus * energiansaastoprosentti[_energiasaastovalinta];
    totals.vertailu.valaistus = totals.valaistus / 66 * 50;

    /**
		* Lopputulos
		*
		*/

    totals.total = 0;
    totals.total += totals.lammitys;
    totals.total += totals.auto;
    totals.total += _lamminVesi;
    totals.total += totals.kylmasailytys;
    totals.total += totals.vaatehuolto;
    totals.total += totals.sauna;
    totals.total += totals.viihde + totals.standby;
    totals.total += totals.valaistus;

    totals.totalEuro = totals.total * 0.12;

    /**
		* Lopputus vertailu
		*
		*/

    totals.vertailu.total = 0;
    totals.vertailu.total += totals.vertailu.lammitys;
    totals.vertailu.total += totals.vertailu.auto;
    totals.vertailu.total += totals.vertailu.lamminVesi;
    totals.vertailu.total += totals.vertailu.kylmasailytys;
    totals.vertailu.total += totals.vertailu.vaatehuolto;
    totals.vertailu.total += totals.vertailu.sauna;
    totals.vertailu.total += totals.vertailu.viihde;
    totals.vertailu.total += totals.vertailu.valaistus;

    totals.vertailu.totalEuro = totals.vertailu.total * 0.12;

    if (returnObject) {
        return totals;
    } else {
        return totals.total;
    }

}

/**
	* Calculates values needed for results page
	*
	*/

KYMPPIVOIMA.laskuri.calculation.yhteenveto = function(laskuri) {

    var results = laskuri.calculationdata.results
      , details = laskuri.calculationdata.results.details
      , perustiedot = laskuri.calculationdata.values.perustiedot
      , kotitalous = laskuri.calculationdata.values.kotitalous
      , helpers = laskuri.calculation.helpers;

    var kotitaloussahko = laskuri.calculation.kotitaloussahko(laskuri, true)
      , vertailuarvot = kotitaloussahko.vertailu;
    /*,
				_lisalattialammitys 	= helpers.pesutilaLammitys(laskuri.calculationdata.values.lammitys, 0);*/

    // lämmitys
    details.lammitys = kotitaloussahko.lammitys;
    details.lammitys_vertailuarvo = vertailuarvot.lammitys;
    details.lammitys_erotus = details.lammitys - details.lammitys_vertailuarvo;

    // autolämmitys
    details.autolammitys = kotitaloussahko.auto;
    details.autolammitys_vertailuarvo = vertailuarvot.auto;
    details.autolammitys_erotus = details.autolammitys - details.autolammitys_vertailuarvo;

    // käyttöveden lämmitys
    details.kayttovedenlammitys = results.groups.lamminVesi;
    details.kayttovedenlammitys_vertailuarvo = vertailuarvot.lamminVesi;
    details.kayttovedenlammitys_erotus = details.kayttovedenlammitys - details.kayttovedenlammitys_vertailuarvo;

    // kylmasailytys ja ruuan laitto
    details.kylmasailytys = kotitaloussahko.kylmasailytys;
    details.kylmasailytys_vertailuarvo = vertailuarvot.kylmasailytys;
    details.kylmasailytys_erotus = details.kylmasailytys - details.kylmasailytys_vertailuarvo;

    // vaatehuolto
    details.vaatehuolto = kotitaloussahko.vaatehuolto;
    details.vaatehuolto_vertailuarvo = vertailuarvot.vaatehuolto;
    details.vaatehuolto_erotus = details.vaatehuolto - details.vaatehuolto_vertailuarvo;

    // saunominen
    details.saunominen = kotitaloussahko.sauna;
    details.saunominen_vertailuarvo = vertailuarvot.sauna;
    details.saunominen_erotus = details.saunominen - details.saunominen_vertailuarvo;

    // viihde
    details.viihde = kotitaloussahko.viihde + kotitaloussahko.standby;
    details.viihde_vertailuarvo = vertailuarvot.viihde;
    details.viihde_erotus = details.viihde - details.viihde_vertailuarvo;

    // valaistus
    details.valaistus = kotitaloussahko.valaistus;
    details.valaistus_vertailuarvo = vertailuarvot.valaistus;
    details.valaistus_erotus = details.valaistus - details.valaistus_vertailuarvo;

    // total
    details.total = kotitaloussahko.total;
    details.total_vertailuarvo = vertailuarvot.total;
    details.total_erotus = details.total - details.total_vertailuarvo;

    // total euro
    details.totalEuro = kotitaloussahko.totalEuro;
    details.totalEuro_vertailuarvo = vertailuarvot.totalEuro;
    details.totalEuro_erotus = details.totalEuro - details.totalEuro_vertailuarvo;

    details.pdfData = [{
        label: "Lämmitys",
        value_user: details.lammitys,
        value_compare: details.lammitys_vertailuarvo
    }, {
        label: "Käyttöveden lämmitys",
        value_user: details.kayttovedenlammitys,
        value_compare: details.kayttovedenlammitys_vertailuarvo
    }, {
        label: "Kylmäsäilytys ja ruuanlaitto",
        value_user: details.kylmasailytys,
        value_compare: details.kylmasailytys_vertailuarvo
    }, {
        label: "Vaatehuolto",
        value_user: details.vaatehuolto,
        value_compare: details.vaatehuolto_vertailuarvo
    }, {
        label: "Saunominen",
        value_user: details.saunominen,
        value_compare: details.saunominen_vertailuarvo
    }, {
        label: "Viihde- ja tietotekniikka",
        value_user: details.viihde,
        value_compare: details.viihde_vertailuarvo
    }, {
        label: "Valaistus",
        value_user: details.valaistus,
        value_compare: details.vaatehuolto_vertailuarvo
    }, {
        label: "Auton lämmitys",
        value_user: details.autolammitys,
        value_compare: details.autolammitys_vertailuarvo
    }];

    return laskuri;
}


/**
	* Shows results on page
	*
	*/

KYMPPIVOIMA.laskuri.showResults = function(calculationResults) {

    var max = Math.max(calculationResults.total, calculationResults.total_vertailuarvo)
      , percentile = {
        user: {
            lammitys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.lammitys, max),
            kayttovedenlammitys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.kayttovedenlammitys, max),
            kylmasailytys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.kylmasailytys, max),
            vaatehuolto: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.vaatehuolto, max),
            saunominen: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.saunominen, max),
            viihde: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.viihde, max),
            valaistus: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.valaistus, max),
            auto: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.autolammitys, max)
        },
        average: {
            lammitys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.lammitys_vertailuarvo, max),
            kayttovedenlammitys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.kayttovedenlammitys_vertailuarvo, max),
            kylmasailytys: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.kylmasailytys_vertailuarvo, max),
            vaatehuolto: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.vaatehuolto_vertailuarvo, max),
            saunominen: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.saunominen_vertailuarvo, max),
            viihde: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.viihde_vertailuarvo, max),
            valaistus: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.valaistus_vertailuarvo, max),
            auto: KYMPPIVOIMA.laskuri.calculation.helpers.getPercentage(calculationResults.autolammitys_vertailuarvo, max)
        }
    };

    var pieData = [{
        "data": [{
            "itemLabel": "Kylmäsäilytys ja ruoanlaitto",
            "itemValue": calculationResults.kylmasailytys
        }, {
            "itemLabel": "Valaistus",
            "itemValue": calculationResults.valaistus
        }, {
            "itemLabel": "Saunominen",
            "itemValue": calculationResults.saunominen
        }, {
            "itemLabel": "Viihde- ja tietotekniikka",
            "itemValue": calculationResults.viihde
        }, {
            "itemLabel": "Vaatehuolto",
            "itemValue": calculationResults.vaatehuolto
        }, {
            "itemLabel": "Lämmitys",
            "itemValue": calculationResults.lammitys
        }, {
            "itemLabel": "Käyttöveden lämmitys",
            "itemValue": calculationResults.kayttovedenlammitys
        }, {
            "itemLabel": "Auton lämmitys",
            "itemValue": calculationResults.autolammitys
        }],
        "label": "Oma kulutus"
    }, {
        "data": [{
            "itemLabel": "Kylmäsäilytys ja ruoanlaitto",
            "itemValue": 12
        }, {
            "itemLabel": "Valaistus",
            "itemValue": 10
        }, {
            "itemLabel": "Saunominen",
            "itemValue": 5
        }, {
            "itemLabel": "Sähkölaitteet",
            "itemValue": 22
        }, {
            "itemLabel": "Vaatehuolto",
            "itemValue": 5
        }, {
            "itemLabel": "Lämmitys",
            "itemValue": 20
        }, {
            "itemLabel": "Käyttöveden lämmitys",
            "itemValue": 10
        }],
        "label": "Keskiverto"
    }];

    $("#kulutus-oma .arvo").text(Math.round(calculationResults.total));
    $("#kulutus-vertailu .arvo").text(Math.round(calculationResults.total_vertailuarvo));
    $("#hinta-oma .arvo").text(Math.round(calculationResults.totalEuro));
    $("#hinta-vertailu .arvo").text(Math.round(calculationResults.totalEuro_vertailuarvo));

    NITRO.drawGraph.update(pieData);

    /**
         * print results
         */
    $("#tulokset-lammitys .comparebox-value").text(Math.round(calculationResults.lammitys));
    $("#tulokset-vesi .comparebox-value").text(Math.round(calculationResults.kayttovedenlammitys));
    $("#tulokset-kylmasailytys .comparebox-value").text(Math.round(calculationResults.kylmasailytys));
    $("#tulokset-vaatehuolto .comparebox-value").text(Math.round(calculationResults.vaatehuolto));
    $("#tulokset-saunominen .comparebox-value").text(Math.round(calculationResults.saunominen));
    $("#tulokset-viihde .comparebox-value").text(Math.round(calculationResults.viihde));
    $("#tulokset-valaistus .comparebox-value").text(Math.round(calculationResults.valaistus));
    $("#tulokset-auto .comparebox-value").text(Math.round(calculationResults.autolammitys));
    $("#tulokset-yht-kwh .comparebox-value").text(Math.round(calculationResults.total));
    $("#tulokset-yht-euro .comparebox-value").text(Math.round(calculationResults.totalEuro));

    /**
         * set bar widths
         */
    $("#tulokset-lammitys .graph-bar-user").width(percentile.user.lammitys + "%");
    $("#tulokset-lammitys .graph-bar-average").width(percentile.average.lammitys + "%");

    $("#tulokset-vesi .graph-bar-user").width(percentile.user.kayttovedenlammitys + "%");
    $("#tulokset-vesi .graph-bar-average").width(percentile.average.kayttovedenlammitys + "%");

    $("#tulokset-kylmasailytys .graph-bar-user").width(percentile.user.kylmasailytys + "%");
    $("#tulokset-kylmasailytys .graph-bar-average").width(percentile.average.kylmasailytys + "%");

    $("#tulokset-vaatehuolto .graph-bar-user").width(percentile.user.vaatehuolto + "%");
    $("#tulokset-vaatehuolto .graph-bar-average").width(percentile.average.vaatehuolto + "%");

    $("#tulokset-saunominen .graph-bar-user").width(percentile.user.saunominen + "%");
    $("#tulokset-saunominen .graph-bar-average").width(percentile.average.saunominen + "%");

    $("#tulokset-viihde .graph-bar-user").width(percentile.user.viihde + "%");
    $("#tulokset-viihde .graph-bar-average").width(percentile.average.viihde + "%");

    $("#tulokset-valaistus .graph-bar-user").width(percentile.user.valaistus + "%");
    $("#tulokset-valaistus .graph-bar-average").width(percentile.average.valaistus + "%");

    $("#tulokset-auto .graph-bar-user").width(percentile.user.auto + "%");
    $("#tulokset-auto .graph-bar-average").width(percentile.average.auto + "%");

}

