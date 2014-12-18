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
$rows[] = array('http://www.weg.de', '.weg.de', 'Weg.de', 'Reise', 'affilinet', 'http://publisher.affili.net/Creatives/showCreatives.aspx?lcid=67917&pid=9104');
$rows[] = array('http://www.expedia.de/', '.expedia.', 'Expedia.de', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=10928');
$rows[] = array('http://www.ab-in-den-urlaub.de/', '.ab-in-den-urlaub.de', 'Ab-in-den-Urlaub.de', 'Reise', 'zanox', 'http://www.zanox-affiliate.de/ppc/?28216342C438668017T&ULP=[[9333]]');
$rows[] = array('http://www.lastminute.de/', '.lastminute.de', 'lastminute.de', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=4659');
$rows[] = array('http://www.amazon.de/', '.amazon.', 'Amazon', 'Allgemein', 'amazon', 'https://partnernet.amazon.de/gp/associates/join/landing/main.html');
$rows[] = array('http://www.lidl.de/', '.lidl.de', 'Lidl', 'Allgemein', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=5050');
$rows[] = array('http://www.lidl-reisen.de/', '.lidl-reisen.de', 'Lidl Reisen', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=5050');
$rows[] = array('http://www.ab-in-den-urlaub-deals.de/', '.ab-in-den-urlaub-deals.de', 'Ab-in-den-Urlaub-Deals', 'Reise', 'zanox', 'http://www.zanox-affiliate.de/ppc/?28216342C438668017T&ULP=[[10413]]');
$rows[] = array('http://www.hrs.de/', '.hrs.', 'HRS', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=233752');
$rows[] = array('http://www.hrs-deals.de/', '.hrs-deals.', 'HRS Deals', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=233752');
$rows[] = array('http://www.hlx.com/', '.hlx.com', 'HLX', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoLinksRead.action?programId=245150');
$rows[] = array('http://www.tropo.de/', '.tropo.de', 'Tropo', 'Reise', 'affilinet', 'http://publisher.affili.net/Creatives/showCreatives.aspx?pid=12290');
$rows[] = array('http://www.tui.com/', '.tui.com', 'Tui', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=9881');
$rows[] = array('http://www.mydays.de', 'mydays.de', 'mydays', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=3073');
$rows[] = array('http://www.holidaycheck.de', '.holidaycheck.de', 'HolidayCheck', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=7412');
$rows[] = array('http://www.ebookers.de', '.ebookers.de', 'ebookers.de', 'Reise', 'zanox', 'http://www.zanox-affiliate.de/ppc/?28216342C438668017T&ULP=[[1635]]');
$rows[] = array('http://www.5vorflug.de', '.5vorflug.de', '5vorFlug', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/programInfo.aspx?pid=9841');
$rows[] = array('http://www.hotels.com', '.hotels.com', 'Hotels.com', 'Reise', 'tradedoubler', 'http://login.tradedoubler.com/pan/aProgramInfoApplyRead.action?programId=67379');
$rows[] = array('http://www.sonnenklar.tv', '.sonnenklar.tv', 'Sonnenklar.TV', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=3719');
$rows[] = array('http://www.aida.de', '.aida.de', 'AIDA', 'Reise', 'zanox', 'xx');
$rows[] = array('http://www.flixbus.de', 'flixbus.de', 'Flixbus', 'Reise', 'affilinet', 'http://publisher.affili.net/Programs/ProgramInfo.aspx?pid=5050');

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