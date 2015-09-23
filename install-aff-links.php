<?php

/* STRUKTUR:
1. url = Url zur Startseite, zum Beispiel 'http://www.weg.de' UNIQUE
2. url_match = Teil der URL, der übereinstimmen muss, z. B. '.weg.de' UNIQUE
3. ap_name = Name des Netzwerks
4. ap_cat = Category, z. B. 'Reise'
5. netzwerke = Verfügbare netzwerke Kommagetrennt, z. B. 'zanox, affili.net'
    (  6. nw_aktiv = eines der Netzwerke, wird durch user gesetzt)
    (  7. aff_code = Affiliate-Code des Users für dieses Programm)
8. nw_link = Link zum Partnerporgramm beim Netzwerk

NETZWERKE
affilinet
zanox
tradedoubler

Die Netzwerkliste muss in wp2affiliate.php und aff_links.js gepflegt werden!!! 

TODO: Netzwerk-Links bei Zanox anpassen (ohne eigene Partner-ID)

*/

// Hier werden die Aff-Programme gepflegt. Beim install oder update werden die Werte in die DB geschrieben
$rows[] = array('http://www.weg.de', '.weg.de', 'Weg.de', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=43802');
$rows[] = array('http://www.expedia.de/', '.expedia.', 'Expedia.de', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.ab-in-den-urlaub.de/', '.ab-in-den-urlaub.de', 'Ab-in-den-Urlaub.de', 'Reise', 'zanox', 'http://www.zanox-affiliate.de/ppc/?28216342C438668017T&ULP=[[9333]]');
$rows[] = array('http://www.lastminute.de/', '.lastminute.de', 'lastminute.de', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=4659');
$rows[] = array('http://www.amazon.de/', '.amazon.', 'Amazon', 'Allgemein', 'amazon', 'https://partnernet.amazon.de/gp/associates/join/landing/main.html');
$rows[] = array('http://www.lidl.de/', '.lidl.de', 'Lidl', 'Allgemein', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=5050');
$rows[] = array('http://www.lidl-reisen.de/', '.lidl-reisen.de', 'Lidl Reisen', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=5050');
$rows[] = array('http://www.ab-in-den-urlaub-deals.de/', '.ab-in-den-urlaub-deals.de', 'Ab-in-den-Urlaub-Deals', 'Reise', 'zanox', 'http://www.zanox-affiliate.de/ppc/?28216342C438668017T&ULP=[[10413]]');
$rows[] = array('http://www.hrs.de/', '.hrs.', 'HRS', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=233752');
$rows[] = array('http://www.hrs-deals.de/', '.hrs-deals.', 'HRS Deals', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=233752');
$rows[] = array('http://www.hlx.com/', '.hlx.com', 'HLX', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoLinksRead.action?programId=245150');
$rows[] = array('http://www.tropo.de/', '.tropo.de', 'Tropo', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=256266');
$rows[] = array('http://www.tui.com/', '.tui.com', 'Tui', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=9881');
$rows[] = array('http://www.mydays.de', 'mydays.de', 'mydays', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=3073');
$rows[] = array('http://www.holidaycheck.de', '.holidaycheck.de', 'HolidayCheck', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=7412');
$rows[] = array('http://www.ebookers.de', '.ebookers.de', 'ebookers.de', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.5vorflug.de', '.5vorflug.de', '5vorFlug', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/programInfo.aspx?pid=9841');
$rows[] = array('http://www.hotels.com', '.hotels.com', 'Hotels.com', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.sonnenklar.tv', '.sonnenklar.tv', 'Sonnenklar.TV', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=3719');
$rows[] = array('http://www.aida.de', '.aida.de', 'AIDA', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.zalando.de/', '.zalando.de', 'Zalando', 'Fashion', 'affilinet', 'http://publisher.affili.net/Creatives/showCreatives.aspx?pid=5643');
$rows[] = array('https://www.digistore24.com/', '.digistore24.', 'Digistore24', 'Marktplatz', 'digistore24', 'https://www.digistore24.com/');
$rows[] = array('https://www.dkb.de', '.dkb.de', 'DKB.de', 'Finanzen', 'zanox', 'xx');
$rows[] = array('http://www.jochen-schweizer.de', '.jochen-schweizer.', 'Jochen Schweizer', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=3511');
$rows[] = array('http://www.thalia.de/', '.thalia.', 'Thalia', 'Buecher', 'zanox', 'xx');
$rows[] = array('http://www.travador.com', '.travador.com', 'Travador.com', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=12456');
$rows[] = array('http://www.conrad.de', '.conrad.de', 'Conrad Electronic', 'Elektronik', 'affilinet', 'http://publisher.affili.net/Programs/programInfo.aspx?pid=2945');
$rows[] = array('http://www.saturn.de', '.saturn.de', 'Saturn', 'Elektronik', 'affilinet', 'http://publisher.affili.net/Programs/programInfo.aspx?pid=9853');
$rows[] = array('http://meinfernbus.de', 'meinfernbus.de', 'MeinFernbus', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=11694');
$rows[] = array('http://www.skyscanner.de/', '.skyscanner.', 'Skyscanner', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=224467');
$rows[] = array('http://www.fluege.de', '.fluege.de', 'Fluege.de', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.hotel.de', '.hotel.de', 'Hotel.de', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.wefashion.de', '.wefashion.de', 'WE Fashion', 'Fashion', 'zanox', 'xx');
$rows[] = array('http://www.topdeals.de/', '.topdeals.de', 'topdeals.de', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=8950');

// Fürs Testing:
// $rows[] = array('http://www.whatismyreferer.com', '.whatismyreferer.com', 'Whtaismyreferer', 'Test', '', '');

// Array-Zeilen einzeln durchgehen
foreach ($rows as $row) {
    $url = $row[0];
    $url_match = $row[1];
    $ap_name = $row[2];
    $ap_cat = $row[3];
    $netzwerke = $row[4];
    $nw_link = $row[5];
    
// Jede Zeile einzeln in die DB schreiben. Falls eine URL schon vorhanden ist, wird nur nw_ids und nw_link ersetzt
		$q = "INSERT INTO {$wpdb->prefix}wp2ap_aff_links(url, url_match, ap_name, ap_cat, netzwerke, nw_link) VALUES('$url', '$url_match', '$ap_name', '$ap_cat', '$netzwerke', '$nw_link')
			  ON DUPLICATE KEY UPDATE netzwerke = '$netzwerke', ap_name ='$ap_name', ap_cat ='$ap_cat', nw_link = '$nw_link'";
		$q = $wpdb->prepare( $q, $id, current_time('mysql') );
    dbDelta( $q );

};

?>