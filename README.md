# lms-plus-qgis
<s>Detailed tutorial</s> maybe someday...  
explaining how to connect QGIS to an LMS (or other external) database and how to process the downloaded data

Poniżej metoda wyciągnięcia do QGISa danych bezpośrednio z bazy danych LMSa (lub innej bazy) i przetworzenia ich do warstw węzły/punkty elastyczności/linie proste pomiędzy urządzeniami do edycji (nie skończone w 100% - ale większość linii rysuje prawidłowo)

## Aktualnie pracuję nad triggerami, które pozwolą połączyć warstwy (na poziomie bazy danych) w taki sposób, żeby działanie na jednej automatycznie było przenoszone na pozostałe, np. przeciągnięcie PE w inne miejsce na mapie będzie powodowało automatyczne przeciągnięcie węzłów podłączonych do niego i przeliczenie geometrii linii (czyli "przerysowanie" ich na mapie zgodnie z wprowadzonymi zmianami)

1. Dodajemy swoją bazę lmsa (bądź inną) przez "Layer" -> "Data source manager" -> <b>postgresql</b>. Powinniśmy mieć połączenie i móc przeglądać bazę. Szczegóły znajdziecie w sieci, być może jutro (jak zdążę) wrzucę krótki tutorial konfiguracji QGISa pod pracę na dedykowanej bazie.

2. QGIS <b>bardzo słabo</b> radzi sobie z bardziej skomplikowanymi zapytaniami do baz zewnętrznych. ALE - jest rozwiązanie i na to. Zamiast w QGISIE używać np.:

```
SELECT DISTINCT n.id /*:int*/ AS lms_dev_id, CONCAT(n.id, '_', REPLACE(n.name, ' ', '')),
CONCAT(ls.ident, ld.ident, lb.ident, lb.type) /*:int*/ AS TERC,
(...)
ls.name /*:text*/ AS state_name, ls.ident /*:int*/ AS state_ident
FROM lms_netdevices n
INNER JOIN lms_addresses addr        ON n.address_id = addr.id
(...)
INNER JOIN lms_netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id;
```
wystarczy utworzyć widok (VIEW) - oczywiście bezpośrednio w bazie LMSa/innej:
```
CREATE VIEW vtable_for_qgis AS
SELECT DISTINCT n.id /*:int*/ AS lms_dev_id, CONCAT(n.id, '_', REPLACE(n.name, ' ', '')),
CONCAT(ls.ident, ld.ident, lb.ident, lb.type) /*:int*/ AS TERC,
(...)
ls.name /*:text*/ AS state_name, ls.ident /*:int*/ AS state_ident
FROM lms_netdevices n
INNER JOIN lms_addresses addr        ON n.address_id = addr.id
(...)
INNER JOIN lms_netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id;
```
  
i teraz zapytanie ```SELECT * FROM vtable_for_qgis;``` w QGISie zwróci nam wynik w pół sekundy. 

### Poprawione i dobrze działające zapytanie SQL o unikalne punkty adresowe, wraz z krótkim wyjaśnieniem:

* Podstawowe zapytanie generujące listę urządzeń posiadających linki - z duplikatami:
```
SELECT n.id AS lms_dev_id,CONCAT(n.id, '_', REPLACE(n.name, ' ', '')) AS uke_report_namepart,
                          CASE
                              WHEN n.id = nl.src THEN nl.dst
                              WHEN n.id = nl.dst THEN nl.src
                          END AS destinationdevid,
                          n.longitude AS dlugosc,
                          n.latitude AS szerokosc,
                          CONCAT(ls.ident, ld.ident, lb.ident, lb.type) AS terc,
                          lc.ident AS simc,
                          lst.ident AS ulic,
                          addr.house AS nr_porzadkowy,
                          n.address_id AS lms_addr_id,
                          CONCAT(lst.name2, ' ', lst.name) AS ulica,
                          lc.name AS city_name,
                          lb.name AS borough_name, lb.ident AS borough_ident, lb.type AS borough_type,
                          ld.name AS district_name, ld.ident AS district_ident,
                          ls.name AS state_name, ls.ident AS state_ident
FROM netdevices n
         LEFT JOIN addresses addr        ON n.address_id = addr.id
         LEFT JOIN location_streets lst  ON lst.id = addr.street_id
         LEFT JOIN location_cities lc    ON lc.id = addr.city_id
         LEFT JOIN location_boroughs lb  ON lb.id = lc.boroughid
         LEFT JOIN location_districts ld ON ld.id = lb.districtid
         LEFT JOIN location_states ls    ON ls.id = ld.stateid
         INNER JOIN netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id;
```
* Z ww. chcę wybrać unikalny punkt adresowy (terc, simc, ulic, nr_porzadkowy) po ilości linków - zakładam że pod danym adresem urządzenie mające więcej linków jest "główne". Dokładam więc licznik:
```
SELECT lms_dev_id, COUNT(lms_dev_id) AS linkscount
FROM tmp2
GROUP BY lms_dev_id
```
* Teraz całe zapytanie wygląda tak:
```
WITH mymainquery AS
         (SELECT n.id AS lms_dev_id,CONCAT(n.id, '_', REPLACE(n.name, ' ', '')) AS uke_report_namepart,
                          CASE
                              WHEN n.id = nl.src THEN nl.dst
                              WHEN n.id = nl.dst THEN nl.src
                          END AS destinationdevid,
                          n.longitude AS dlugosc, 
						  n.latitude AS szerokosc,
                          CONCAT(ls.ident, ld.ident, lb.ident, lb.type) AS terc,
                          lc.ident AS simc,
                          lst.ident AS ulic,
                          addr.house AS nr_porzadkowy,
                          n.address_id AS lms_addr_id,
                          CONCAT(lst.name2, ' ', lst.name) AS ulica,
                          lc.name AS city_name,
                          lb.name AS borough_name, lb.ident AS borough_ident, lb.type AS borough_type,
                          ld.name AS district_name, ld.ident AS district_ident,
                          ls.name AS state_name, ls.ident AS state_ident
FROM netdevices n
         LEFT JOIN addresses addr        ON n.address_id = addr.id
         LEFT JOIN location_streets lst  ON lst.id = addr.street_id
         LEFT JOIN location_cities lc    ON lc.id = addr.city_id
         LEFT JOIN location_boroughs lb  ON lb.id = lc.boroughid
         LEFT JOIN location_districts ld ON ld.id = lb.districtid
         LEFT JOIN location_states ls    ON ls.id = ld.stateid
         INNER JOIN netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id),
     mynetlinkscount AS
         (SELECT lms_dev_id, COUNT(lms_dev_id) AS linkscount
          FROM mymainquery
          GROUP BY lms_dev_id)
SELECT count.linkscount, main.* FROM mymainquery main
INNER JOIN mynetlinkscount count ON main.lms_dev_id = count.lms_dev_id
ORDER BY lms_dev_id, linkscount;
```
od "mynetlinkscount AS" zaczyna się drugie sub-query typu WITH..AS  
  
W tej chwili w zapytaniu dołożyło mi kolumnę "linkscount" pokazującą ilość połączeń dla każdego lms_dev_id.  
  
* Teraz muszę wybrać unikalny adres, który do tego ma najwięcej linków. Wspomagam się zapytaniem (najpierw zrobiłem VIEW o nazwie tmp z powyższego głównego zapytania):  
```
SELECT terc, simc, ulic, nr_porzadkowy, linkscount, lms_dev_id from tmp
where terc = '2061011' and simc = '0922410' and ulic = '00432' and nr_porzadkowy = '12'
ORDER BY terc, simc, ulic, nr_porzadkowy, linkscount DESC, lms_dev_id;
```
które u mnie zwraca:  
```
  terc   |  simc   | ulic  | nr_porzadkowy | linkscount | lms_dev_id
---------+---------+-------+---------------+------------+------------
 2061011 | 0922410 | 00432 | 12            |          3 |          2
 2061011 | 0922410 | 00432 | 12            |          3 |          2
 2061011 | 0922410 | 00432 | 12            |          3 |          2
 2061011 | 0922410 | 00432 | 12            |          1 |        560
```
  
Widać więc że ostatecznie lms_dev_id = 2 musi zostać wybrane. Na wynik ma tu największy wpływ <b>linkscount DESC</b>, bo:  
```
SELECT DISTINCT ON (terc, simc, ulic, nr_porzadkowy) terc, simc, ulic, nr_porzadkowy, linkscount, lms_dev_id from tmp
where terc = '2061011' and simc = '0922410' and ulic = '00432' and nr_porzadkowy = '12'
ORDER BY terc, simc, ulic, nr_porzadkowy, linkscount DESC, lms_dev_id;

zwraca:

  terc   |  simc   | ulic  | nr_porzadkowy | linkscount | lms_dev_id
---------+---------+-------+---------------+------------+------------
 2061011 | 0922410 | 00432 | 12            |          3 |          2

```
ALE powyższe, tylko z linkscount ASC (domyślny kierunek - czyli pusta wartość):  
```
SELECT DISTINCT ON (terc, simc, ulic, nr_porzadkowy) terc, simc, ulic, nr_porzadkowy, linkscount, lms_dev_id from tmp
where terc = '2061011' and simc = '0922410' and ulic = '00432' and nr_porzadkowy = '12'
ORDER BY terc, simc, ulic, nr_porzadkowy, linkscount, lms_dev_id;

zwraca:
 
  terc   |  simc   | ulic  | nr_porzadkowy | linkscount | lms_dev_id
---------+---------+-------+---------------+------------+------------
 2061011 | 0922410 | 00432 | 12            |          1 |        560
```

I rzeczywiście, używając w końcu:  
```
SELECT DISTINCT ON (terc, simc, ulic, nr_porzadkowy) terc, simc, ulic, nr_porzadkowy, linkscount, lms_dev_id from tmp
ORDER BY terc, simc, ulic, nr_porzadkowy, linkscount DESC, lms_dev_id;
```
otrzymuję poprawny wynik (który akurat dla mojej bazy znam). Czyli zapytanie na podstawie którego w QGISie WYZNACZYMY UNIKALNE PUNKTY ADRESOWE ("główne" punkty elastyczności) aktualnie przyjmuje postać (od razu robię z niego VIEW):  
```
CREATE VIEW netdevices_vtable_for_qgis AS
WITH mymainquery AS
         (SELECT n.id AS lms_dev_id,CONCAT(n.id, '_', REPLACE(n.name, ' ', '')) AS uke_report_namepart,
                          CASE
                              WHEN n.id = nl.src THEN nl.dst
                              WHEN n.id = nl.dst THEN nl.src
                          END AS destinationdevid,
                          n.longitude AS dlugosc, 
						  n.latitude AS szerokosc,
                          CONCAT(ls.ident, ld.ident, lb.ident, lb.type) AS terc,
                          lc.ident AS simc,
                          lst.ident AS ulic,
                          addr.house AS nr_porzadkowy,
                          n.address_id AS lms_addr_id,
                          CONCAT(lst.name2, ' ', lst.name) AS ulica,
                          lc.name AS city_name,
                          lb.name AS borough_name, lb.ident AS borough_ident, lb.type AS borough_type,
                          ld.name AS district_name, ld.ident AS district_ident,
                          ls.name AS state_name, ls.ident AS state_ident
FROM netdevices n
         LEFT JOIN addresses addr        ON n.address_id = addr.id
         LEFT JOIN location_streets lst  ON lst.id = addr.street_id
         LEFT JOIN location_cities lc    ON lc.id = addr.city_id
         LEFT JOIN location_boroughs lb  ON lb.id = lc.boroughid
         LEFT JOIN location_districts ld ON ld.id = lb.districtid
         LEFT JOIN location_states ls    ON ls.id = ld.stateid
         INNER JOIN netlinks nl ON (n.id = nl.src OR n.id = nl.dst)
ORDER BY n.id),
     mynetlinkscount AS
         (SELECT lms_dev_id, COUNT(lms_dev_id) AS linkscount
          FROM mymainquery
          GROUP BY lms_dev_id)
SELECT DISTINCT ON (terc, simc, ulic, nr_porzadkowy) count.linkscount, main.* FROM mymainquery main
INNER JOIN mynetlinkscount count ON main.lms_dev_id = count.lms_dev_id
ORDER BY terc, simc, ulic, nr_porzadkowy, linkscount DESC, lms_dev_id;
```
  
  
* Do utworzenia połączeń na mapie będę potrzebował danych o urządzeniu <destinationdevid>, ALE:  
a) ponieważ wycięliśmy powtarzające się adresy, a co za tym idzie niektóre <lms_dev_id> - może się zdarzyć (i jeśli mamy choć jeden adres pod którym jest więcej niż 1 urządzenie to NA PEWNO się zdarzy), że poszukiwanego destinationdevid NIE MA NA MAPIE  
b) do tego pojedyncze urządzenie może mieć dowolną ilość połączeń w tabeli netlinks  
ALE:  
c) na 100% jest na mapie punkt adresowy ("główny" punkt elastyczności) mający TAKIE SAME DANE ADRESOWE jak poszukiwane <destinationdevid>.  
d) samo wyszukiwanie połączeń pomiędzy urządzeniami też nie jest takie najprostsze - bo w netlinks możemy mieć szukane ID zarówno w src jak i dst. Można to sprawdzić zapytaniem:
```
SELECT n.src, n.dst FROM netlinks n
WHERE (n.src IN (SELECT n1.dst FROM netlinks n1) AND n.dst IN (SELECT n2.src FROM netlinks n2));
```
  
  
* Ponieważ wybierając punkty adresowe o największej ilości linków nie mam gwarancji że wziąłęm pod uwagę wszystkie src z netlinks - trzeba będzie wziąć pod uwagę również destination. Najpierw przerobię tę tabelę na taką formę, żeby mieć w niej (terc, simc, ulic, nr_porzadkowy) zarówno dla src jak i dst (od razu do VIEW):  
```
CREATE VIEW netlinks_with_coords_vtable_for_qgis AS
SELECT nl.id AS netlinksid, nl.src,
       CONCAT(n1.id, '_', REPLACE(n1.name, ' ', '')) AS srcnamepart,
       n1.longitude AS srclongitude, n1.latitude AS srclatitude,
       CONCAT(ls1.ident, ld1.ident, lb1.ident, lb1.type) AS srcTERC,
       lc1.ident AS srcSIMC,
       lst1.ident AS srcULIC,
       addr1.house AS srcnr_porzadkowy,
       nl.dst,
       CONCAT(n2.id, '_', REPLACE(n2.name, ' ', '')) AS dstnamepart,
       n2.longitude AS dstlongitude, n2.latitude AS dstlatitude,
       CONCAT(ls2.ident, ld2.ident, lb2.ident, lb2.type) AS dstTERC,
       lc2.ident AS dstSIMC,
       lst2.ident AS dstULIC,
       addr2.house AS dstnr_porzadkowy
FROM netlinks nl
         INNER JOIN netdevices n1 ON n1.id = nl.src
         LEFT JOIN addresses addr1        ON n1.address_id = addr1.id
         LEFT JOIN location_streets lst1  ON lst1.id = addr1.street_id
         LEFT JOIN location_cities lc1    ON lc1.id = addr1.city_id
         LEFT JOIN location_boroughs lb1  ON lb1.id = lc1.boroughid
         LEFT JOIN location_districts ld1 ON ld1.id = lb1.districtid
         LEFT JOIN location_states ls1    ON ls1.id = ld1.stateid
         INNER JOIN netdevices n2 ON n2.id = nl.dst
         LEFT JOIN addresses addr2        ON n2.address_id = addr2.id
         LEFT JOIN location_streets lst2  ON lst2.id = addr2.street_id
         LEFT JOIN location_cities lc2    ON lc2.id = addr2.city_id
         LEFT JOIN location_boroughs lb2  ON lb2.id = lc2.boroughid
         LEFT JOIN location_districts ld2 ON ld2.id = lb2.districtid
         LEFT JOIN location_states ls2    ON ls2.id = ld2.stateid;
```
  
  
  
* POPRAWIONE: użyłem do wyciągnięcia danych do QGISa poniższego zapytania (według mnie rysuje wszystkie linki):  
```
CREATE VIEW netlinks_with_coords_distinct_for_qgis AS
SELECT dev.lms_dev_id AS src, dev.uke_report_namepart AS srcnamepart, dev.dlugosc AS srclongitude, dev.szerokosc AS srclatitude,
       dev.terc AS srcterc, dev.simc AS srcsimc, dev.ulic AS srculic, dev.nr_porzadkowy AS srcnr_porzadkowy,
       nl.dst, nl.dstnamepart, nl.dstlongitude, nl.dstlatitude, nl.dstterc, nl.dstsimc, nl.dstulic, nl.dstnr_porzadkowy
FROM netdevices_vtable_for_qgis dev
     JOIN netlinks_with_coords_vtable_for_qgis nl ON
        (dev.terc = nl.srcterc AND
        dev.simc = nl.srcsimc AND
        dev.ulic = nl.srculic AND
        dev.nr_porzadkowy = nl.srcnr_porzadkowy)
WHERE (nl.dstterc, nl.dstsimc, nl.dstulic, nl.dstnr_porzadkowy)
IN (SELECT terc, simc, ulic, nr_porzadkowy FROM netdevices_vtable_for_qgis)
AND CONCAT(nl.dstterc, nl.dstsimc, nl.dstulic, nl.dstnr_porzadkowy) != CONCAT(dev.terc, dev.simc, dev.ulic, dev.nr_porzadkowy);
```
  
### Koniec SQL w LMSie. W tej chwili powinniśmy do QGISa pobrać dwie warstwy wirtualne z LMSa: 
```
SELECT * FROM netdevices_vtable_for_qgis;
```
oraz:
```
SELECT * FROM netlinks_with_coords_distinct_for_qgis;
```

Po pomyślnym podłączeniu do LMSa w QGISie można albo z palca utworzyć "New virtual layer", albo wyedytować w załączonych plikach: [lms_devices_1_raw_distinct_addresspoints.qlr](./virtual_layers/lms_devices_1_raw_distinct_addresspoints.qlr) oraz [lms_devices_4_netlinks_raw.qlr](./virtual_layers/lms_devices_4_netlinks_raw.qlr) dokładnie 4 elementy:
* CHANGE_HERE_LMS_DB_NAME_CHANGE_HERE -> x2 -> zamieniamy na nazwę naszej bazy LMS
* CHANGE_HERE_LMS_HOST_IP_CHANGE_HERE -> x2 -> zamieniamy na hostname lub IP naszego hosta LMS

screeny w katalogu [img](./img) z przedrostkiem "lms_devices_1" mogą być tu pomocne. Po edycji pliku dodajemy warstwę przez "Layer" -> "add from layer definition file"

Do przetworzenia ww. danych służą kolejne 3 warstwy wirtualne:  
[lms_devices_2_distinct_wezly.qlr](./virtual_layers/lms_devices_2_distinct_wezly.qlr)  
[lms_devices_3_distinct_punkty_elastycznosci.qlr](./virtual_layers/lms_devices_3_distinct_punkty_elastycznosci.qlr)  
[lms_devices_5_linie_kablowe.qlr](./virtual_layers/lms_devices_5_linie_kablowe.qlr)  
w których analogicznie jak w przykładzie wyżej należy zmienić:
* CHANGE_HERE_QGIS_DB_NAME_CHANGE_HERE -> x2 -> zamieniamy na nazwę naszej bazy QGIS
* CHANGE_HERE_QGIS_HOST_IP_CHANGE_HERE -> x2 -> zamieniamy na hostname lub IP naszego hosta QGIS

  
3. Jeśli pracujemy na QGISie podłączonym do własnej bazy postgisowej [ZDECYDOWANIE ZALECANA METODA!] - w tym momencie robimy import ww. warstw wirtualnych na serwer postgisa, po czym <b>OTWIERAMY ZAIMPORTOWANĄ WARSTWĘ Z BAZY za pomocą dwukliku w DB managerze!</b>. Patrz [lms_devices_1_raw_distinct-import_to_qgis_db.png](./img/lms_devices_1_raw_distinct-import_to_qgis_db.png) i [lms_devices_1_raw_distinct-qgis_db_manager.png](./img/lms_devices_1_raw_distinct-qgis_db_manager.png).

4. Warstwa z ```lms_devices_1_raw_distinct_addresspoints``` zawiera tak naprawdę unikalne punkty adresowe w których mamy jedno lub więcej urządzeń. Na jej podstawie tworzymy węzły i punkty elastyczności - "główne" - albo poprzez edycję [lms_devices_2_distinct_wezly.qlr](./virtual_layers/lms_devices_2_distinct_wezly.qlr) i [lms_devices_3_distinct_punkty_elastycznosci.qlr](./virtual_layers/lms_devices_3_distinct_punkty_elastycznosci.qlr) - albo ręczne dodanie nowej warstwy wirtualnej, kliknięcie "import" wskazanie warstwy "lms_devices_1_raw_distinct" jako źródła i wklejenie w odpowiednie miejsce poniższego zapytania:  

* dla punktów elastyczności:
```
SELECT
fid,
makepoint(dlugosc, szerokosc, 4326) AS geom,
CONCAT('PE_', uke_report_namepart) AS pe01_id_pe,
'08' AS pe02_typ_pe,
CONCAT('WW_', uke_report_namepart) AS pe03_id_wezla,
'tak' AS pe04_pdu,
terc AS pe05_terc,
simc AS pe06_simc,
ulic AS pe07_ulic,
nr_porzadkowy AS pe08_nr_porzadkowy,
szerokosc AS pe09_szerokosc,
dlugosc AS pe10_dlugosc,
'światłowodowe' AS pe11_medium_transmisyjne,
'{"10 Gigabit Ethernet"}' AS pe12_technologia_dostepowa,
'09' AS pe13_mozliwosc_swiadczenia_uslug,
'nie' AS pe14_finansowanie_publ,
'' AS pe15_numery_projektow_publ
FROM lms_devices_1_raw_distinct_addresspoints;
```

* dla węzłów:  
```
SELECT
lms_dev_id as id,
makepoint(dlugosc, szerokosc, 4326) AS geom,
CONCAT('WW_', uke_report_namepart) AS we01_id_wezla,
'Węzeł własny' AS we02_tytul_do_wezla,
'' AS we03_id_podmiotu_obcego,
terc AS we04_terc,
simc AS we05_simc,
ulic AS we06_ulic,
nr_porzadkowy AS we07_nr_porzadkowy,
szerokosc AS we08_szerokosc,
dlugosc AS we09_dlugosc,
'światłowodowe' AS we10_medium_transmisyjne,
'nie' AS we11_bsa,
'{"1 Gigabit Ethernet"}' AS we12_technologia_dostepowa,
'Ethernet VLAN' AS we13_uslugi_transmisji_danych,
'nie' AS we14_mozliwosc_zwiekszenia_liczby_interfejsow,
'nie' AS we15_finansowanie_publ,
'' AS we16_numery_projektow_publ,
'nie' AS we17_infrastruktura_o_duzym_znaczeniu,
'03' AS we18_typ_interfejsu,
'nie' AS we19_udostepnianie_ethernet
FROM lms_devices_1_raw_distinct_addresspoints;
```


i znowu - obrazki z [img](./img) mogą być pomocne. Z warstw wirtualnych kopiujemy dane przez prawy klik na warstwie, "Open attributes table" -> "copy all", prawy klik na warstwie docelowej, attributes table, <b>enable edit mode (ołówek)</b> i zwykły CTRL+V. W tym momencie wszystkie pobrane węzły/PE powinny się pojawić na mapie.  

UWAGA! Jeśli wywala nam błąd że "uid/fid must be not null" - to znaczy że nasza warstwa docelowa nie ma odpowiedniego defaulta (zakładam że jest zapisana w DB). Poniżej instrukcja dodania sekwencji:
```
ALTER TABLE punkty_elastycznosci 
ALTER COLUMN pe09_szerokosc TYPE float8
USING pe09_szerokosc::double precision;

ALTER TABLE punkty_elastycznosci 
ALTER COLUMN pe10_dlugosc TYPE float8
USING pe10_dlugosc::double precision;


CREATE SEQUENCE IF NOT EXISTS public.wezly_id_seq INCREMENT 1 START 1;
ALTER SEQUENCE public.wezly_id_seq OWNER TO qgisdbuser;
ALTER SEQUENCE IF EXISTS public.wezly_id_seq OWNED BY wezly.fid;
GRANT ALL ON SEQUENCE public.wezly_id_seq TO michal WITH GRANT OPTION;
GRANT ALL ON SEQUENCE public.wezly_id_seq TO postgres;
GRANT ALL ON SEQUENCE public.wezly_id_seq TO qgisdbuser WITH GRANT OPTION;
ALTER TABLE IF EXISTS public.wezly ALTER COLUMN fid SET DEFAULT nextval('wezly_id_seq'::regclass);

CREATE SEQUENCE IF NOT EXISTS public.punkty_elastycznosci_id_seq INCREMENT 1 START 1;
ALTER SEQUENCE public.punkty_elastycznosci_id_seq OWNER TO qgisdbuser;
ALTER SEQUENCE IF EXISTS public.punkty_elastycznosci_id_seq OWNED BY punkty_elastycznosci.fid;
GRANT ALL ON SEQUENCE public.punkty_elastycznosci_id_seq TO michal WITH GRANT OPTION;
GRANT ALL ON SEQUENCE public.punkty_elastycznosci_id_seq TO postgres;
GRANT ALL ON SEQUENCE public.punkty_elastycznosci_id_seq TO qgisdbuser WITH GRANT OPTION;
ALTER TABLE IF EXISTS public.punkty_elastycznosci ALTER COLUMN fid SET DEFAULT nextval('punkty_elastycznosci_id_seq'::regclass);

CREATE SEQUENCE IF NOT EXISTS public.linie_kablowe_id_seq INCREMENT 1 START 1;
ALTER SEQUENCE public.linie_kablowe_id_seq OWNER TO qgisdbuser;
ALTER SEQUENCE IF EXISTS public.linie_kablowe_id_seq OWNED BY linie_kablowe.fid;
GRANT ALL ON SEQUENCE public.linie_kablowe_id_seq TO michal WITH GRANT OPTION;
GRANT ALL ON SEQUENCE public.linie_kablowe_id_seq TO postgres;
GRANT ALL ON SEQUENCE public.linie_kablowe_id_seq TO qgisdbuser WITH GRANT OPTION;
ALTER TABLE IF EXISTS public.linie_kablowe ALTER COLUMN fid SET DEFAULT nextval('linie_kablowe_id_seq'::regclass);

```


6. Linie są nieco bardziej skomplikowane - ale większość (wszystkie?) już się rysuje. Otwieramy nową warstwę wirtualną, importujemy tym razem warstwę "netlinks_with_coords_distinct_for_qgis" a SQL do utworzenia geometrii to:  

```
SELECT
fid,
makeline(makepoint(srclongitude, srclatitude), makepoint (dstlongitude, dstlatitude)) as geom,
CONCAT('LK_', 'PE_', srcnamepart, '___', 'PE_', dstnamepart) AS lk01_id_lk,
CONCAT('PE_', srcnamepart) AS lk02_id_punktu_poczatkowego,
CONCAT('PE_', dstnamepart) AS lk04_id_punktu_koncowego,
'światłowodowe' AS lk05_medium_transmisyjne,
'Linia kablowa umieszczona w kanale technologicznym' AS lk06_rodzaj_linii_kablowej,
'72' AS lk07_liczba_wlokien, 
'72' AS  lk08_liczba_wlokien_wykorzystywanych,
'0' AS lk09_liczba_wlokien_udostepnienia,
'nie' AS lk10_finansowanie_publ,
'' AS lk11_numery_projektow_publ,
'nie' AS lk12_infrastruktura_o_duzym_znaczeniu
FROM lms_devices_4_netlinks_raw;
```

Mam nadzieję że komuś się te informacje przydadzą.  

Jeszcze notka na koniec: st_makeline/st_makepoint -> DB manager, makeline/makepoint -> działania na warstwie wirtualnej.  

Pozdrawiam i powodzenia! :]  
