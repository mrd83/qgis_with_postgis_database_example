# lms-plus-qgis
<s>Detailed tutorial</s> maybe someday...  
explaining how to connect QGIS to an LMS (or other external) database and how to process the downloaded data

Nie mam już dziś siły na szczegółowy opis, ale że czas goni to chcę wrzucić cokolwiek - może jeszcze ktoś zdąży skorzystać: poniżej metoda wyciągnięcia do QGISa danych bezpośrednio z LMSa i przetworzenia ich do warstw węzły/punkty elastyczności/linie proste pomiędzy urządzeniami do edycji (nie skończone w 100%)

1. Dodajemy swoją bazę lmsa (bądź inną) przez "Layer" -> "Data source manager" -> <b>postgresql</b>. Powinniśmy mieć połączenie i móc przeglądać bazę. Szczegóły znajdziecie w sieci, teraz nie mam siły tego opisywać.

2. QGIS <b>bardzo słabo</b> radzi sobie z bardziej skomplikowanymi zapytaniami do baz zewnętrznych. ALE - jest rozwiązanie i na to. Zamiast w QGISIE używać np. (poniżej zapytanie do bazy LMS wyciągające aktywne (z linkami) urządzenia sieciowe):

```
SELECT DISTINCT n.id /*:int*/ AS lms_dev_id, CONCAT(n.id, '_', REPLACE(n.name, ' ', '')) /*:text*/ AS uke_report_namepart, n.longitude AS dlugosc /*:real*/, n.latitude AS szerokosc /*:real*/,
CONCAT(ls.ident, ld.ident, lb.ident, lb.type) /*:int*/ AS TERC,
lc.ident /*:int*/ AS SIMC,
lst.ident /*:int*/ AS ULIC,
addr.house /*:int*/ AS nr_porzadkowy,
n.address_id /*:int*/ AS lms_addr_id,
CONCAT(lst.name2, ' ', lst.name) /*:text*/ AS ulica,
lc.name /*:text*/ AS city_name,
lb.name /*:text*/ AS borough_name, lb.ident /*:int*/ AS borough_ident, lb.type /*:int*/ AS borough_type,
ld.name /*:text*/ AS district_name, ld.ident /*:int*/ AS district_ident,
ls.name /*:text*/ AS state_name, ls.ident /*:int*/ AS state_ident
FROM lms_netdevices n
INNER JOIN lms_addresses addr        ON n.address_id = addr.id
INNER JOIN lms_location_streets lst  ON lst.id = addr.street_id
INNER JOIN lms_location_cities lc    ON lc.id = addr.city_id
INNER JOIN lms_location_boroughs lb  ON lb.id = lc.boroughid
INNER JOIN lms_location_districts ld ON ld.id = lb.districtid
INNER JOIN lms_location_states ls    ON ls.id = ld.stateid
INNER JOIN lms_netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id;
```
wystarczy utworzyć widok (VIEW) - oczywiście bezpośrednio w bazie LMSa:
```
CREATE VIEW vtable_for_qgis AS
SELECT DISTINCT n.id /*:int*/ AS lms_dev_id, CONCAT(n.id, '_', REPLACE(n.name, ' ', '')) /*:text*/ AS uke_report_namepart, n.longitude AS dlugosc /*:real*/, n.latitude AS szerokosc /*:real*/,
CONCAT(ls.ident, ld.ident, lb.ident, lb.type) /*:int*/ AS TERC,
lc.ident /*:int*/ AS SIMC,
lst.ident /*:int*/ AS ULIC,
addr.house /*:int*/ AS nr_porzadkowy,
n.address_id /*:int*/ AS lms_addr_id,
CONCAT(lst.name2, ' ', lst.name) /*:text*/ AS ulica,
lc.name /*:text*/ AS city_name,
lb.name /*:text*/ AS borough_name, lb.ident /*:int*/ AS borough_ident, lb.type /*:int*/ AS borough_type,
ld.name /*:text*/ AS district_name, ld.ident /*:int*/ AS district_ident,
ls.name /*:text*/ AS state_name, ls.ident /*:int*/ AS state_ident
FROM netdevices n
INNER JOIN addresses addr        ON n.address_id = addr.id
INNER JOIN location_streets lst  ON lst.id = addr.street_id
INNER JOIN location_cities lc    ON lc.id = addr.city_id
INNER JOIN location_boroughs lb  ON lb.id = lc.boroughid
INNER JOIN location_districts ld ON ld.id = lb.districtid
INNER JOIN location_states ls    ON ls.id = ld.stateid
INNER JOIN netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id;
```
  
i teraz zapytanie ```SELECT DISTINCT ON (terc, simc, ulic, nr_porzadkowy) * FROM vtable_for_qgis;``` w QGISie zwróci nam wynik w pół sekundy. Wynikiem jest lista unikalnych punktów adresowych w których mamy 1+ urządzeń.   
Po pomyślnym podłączeniu do LMSa w QGISie można albo z palca utworzyć "New virtual layer", albo wyedytować w załączonym pliku [lms_devices_1_raw_distinct.qlr](./virtual_layers/lms_devices_1_raw_distinct.qlr) dokładnie 4 elementy:
* CHANGE_HERE_LMS_DB_NAME_CHANGE_HERE -> x2 -> zamieniamy na nazwę naszej bazy LMS  
* CHANGE_HERE_LMS_HOST_IP_CHANGE_HERE -> x2 -> zamieniamy na hostname lub IP naszego hosta LMS   

screeny w katalogu [img](./img) z przedrostkiem "lms_devices_1" mogą być tu pomocne. Po edycji pliku dodajemy warstwę przez "Layer" -> "add from layer definition file"

3. Jeśli pracujemy na QGISie podłączonym do własnej bazy postgisowej [ZDECYDOWANIE ZALECANA METODA!] - w tym momencie robimy import warstwy wirtualnej na serwer postgisa, po czym <b>OTWIERAMY ZAIMPORTOWANĄ WARSTWĘ Z BAZY za pomocą dwukliku w DB managerze!</b>. Patrz [lms_devices_1_raw_distinct-import_to_qgis_db.png](./img/lms_devices_1_raw_distinct-import_to_qgis_db.png) i [lms_devices_1_raw_distinct-qgis_db_manager.png](./img/lms_devices_1_raw_distinct-qgis_db_manager.png).

4. Warstwa zawiera tak na prawdę punkty adresowe w których mamy jedno lub więcej urządzeń. Na dany moment przetworzyłęm ją na węzły i punkty elastyczności, oraz na podstawie punktów elastyczności - linki pomiędzy nimi (tutaj SQL jest mocno do poprawy, ale jakiś pogląd na to co da się dosyć łatwo zrobić będziecie mieli). Nadmieniam że zarówno węzły jak i punkty elastyczności przeszły walidację w UKE. Linki pewnie jutro poprawię i wtedy zobaczymy.

5. Analogicznie robimy dla węzłów i punktów elastyczności - albo edycja [lms_devices_2_distinct_wezly.qlr](./virtual_layers/lms_devices_2_distinct_wezly.qlr) i [lms_devices_3_distinct_punkty_elastycznosci.qlr](./virtual_layers/lms_devices_3_distinct_punkty_elastycznosci.qlr) - albo ręczne dodanie nowej warstwy wirtualnej, kliknięcie "import" wskazanie warstwy "lms_devices_1_raw_distinct" jako źródła i wklejenie w odpowiednie miejsce poniższego zapytania:  
* dla węzłów:  
```
SELECT
fid,
MakePoint(dlugosc, szerokosc, 4326) AS geom,
CONCAT('WW_', '', uke_report_namepart) AS we01_id_wezla,
'Węzeł własny' AS we02_tytul_do_wezla,
'' AS we03_id_podmiotu_obcego,
terc AS we04_terc,
simc AS we05_simc,
ulic AS we06_ulic,
nr_porzadkowy AS we07_nr_porzadkowy,
szerokosc AS we08_szerokosc,
dlugosc AS we09_dlugosc,
'światłowodowe' AS we10_medium_transmisyjne,
'Nie' AS we11_bsa,
'10 Gigabit Ethernet' AS we12_technologia_dostepowa,
'Ethernet VLAN' AS we13_uslugi_transmisji_danych,
'Tak' AS we14_mozliwosc_zwiekszenia_liczby_interfejsow,
'Nie' AS we15_finansowanie_publ,
'' AS we16_numery_projektow_publ,
'Nie' AS we17_infrastruktura_o_duzym_znaczeniu,
'03' AS we18_typ_interfejsu,
'Nie' AS we19_udostepnianie_ethernet
FROM lms_devices_1_raw_distinct;
```

* dla punktów elastyczności:  
```
SELECT
fid,
MakePoint(dlugosc, szerokosc, 4326) AS geom,
CONCAT('PE_', '', uke_report_namepart) AS pe01_id_pe,
'08' AS pe02_typ_pe,
CONCAT('WW_', '', uke_report_namepart) AS pe03_id_wezla,
'tak' AS pe04_pdu,
terc AS pe05_terc,
simc AS pe06_simc,
ulic AS pe07_ulic,
nr_porzadkowy AS pe08_nr_porzadkowy,
szerokosc AS pe09_szerokosc,
dlugosc AS pe10_dlugosc,
'światłowodowe' AS pe11_medium_transmisyjne,
'10 Gigabit Ethernet' AS pe12_technologia_dostepowa,
'09' AS pe13_mozliwosc_swiadczenia_uslug,
'Nie' AS pe14_finansowanie_publ,
'' AS pe15_numery_projektow_publ
FROM lms_devices_1_raw_distinct;
```

i znowu - obrazki z [img](./img) mogą być pomocne. Z warstw wirtualnych kopiujemy dane przez prawy klik na warstwie, "Open attributes table" -> "copy all", prawy klik na warstwie docelowej, attributes table, <b>enable edit mode (ołówek)</b> i zwykły CTRL+V. W tym momencie wszystkie pobrane węzły/PE powinny się pojawić na mapie.  

UWAGA! Jeśli wywala nam błąd że "uid/fid must be not null" - to znaczy że nasza warstwa docelowa nie ma odpowiedniego defaulta (zakładam że jest zapisana w DB). Poniżej instrukcja dodania dla warstwy/tabeli "węzły":
```
CREATE SEQUENCE public.wezly_id_seq INCREMENT 1 START 1;
ALTER SEQUENCE public.wezly_id_seq OWNER TO mójdbuser;
ALTER SEQUENCE public.wezly_id_seq OWNED BY wezly.fid;
GRANT ALL ON SEQUENCE public.wezly_id_seq TO mójdbuser WITH GRANT OPTION;
ALTER TABLE IF EXISTS public.wezly ALTER COLUMN fid SET DEFAULT nextval('wezly_id_seq'::regclass);
```


6. Linie są nieco bardziej skomplikowane - trzeba wyciągnąć z netlinks połączenia zaczynające się i kończące na <b>zestawie (terc, simc, ulic, nr_porzadkowy) </b> - bo po tych kolumnach robiliśmy selecta do QGISa, i takie punkty elastyczności mamy. Jak już wspominałem - tego jeszcze nie skończyłem, szczegóły dorzucę jutro może koło południa, na teraz zapytanie tworzące linki z odpowiednio przygotowanych danych wejściowych:  

```
SELECT
fid,
st_makeline(st_makepoint(start_x, start_y), st_makepoint (end_x, end_y)) as geom,
CONCAT('LK_', uke_report_namepart, destination_dev_name) AS lk01_id_lk,
CONCAT('PE_', '', uke_report_namepart) AS lk02_id_punktu_poczatkowego,
CONCAT('PE_', '', destination_dev_name) AS lk04_id_punktu_koncowego,
'światłowodowe' AS lk05_medium_transmisyjne,
'Linia kablowa umieszczona w kanale technologicznym' AS lk06_rodzaj_linii_kablowej,
'24' AS lk07_liczba_wlokien, 
'24' AS  lk08_liczba_wlokien_wykorzystywanych,
'0' AS lk09_liczba_wlokien_udostepnienia,
'Nie' AS lk10_finansowanie_publ,
'' AS lk11_numery_projektow_publ,
'Nie' AS lk12_infrastruktura_o_duzym_znaczeniu
FROM temp;
```

Na dziś to tyle, wybaczcie ale po dwutygodniowym maratonie nie mam dziśjużsił na więcej. Mam nadzieję że komuś się te informacje przydadzą. Na pytania też postaram się odpowiedzieć.


