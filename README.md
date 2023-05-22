## I. Informacje o projekcie (wersja PL, scroll down for English):


### 1. Odbiorcy:

Ten projekt ma ułatwić zrozumienie i wdrożenie:  
- QGIS-a,  
- bazy danych postgresql z rozszerzeniem postgis,  
- relacji pomiędzy warstwami - skonfigurowanymi zarówno w bazie jak i w samym QGIS-ie (we właściwościach atrybutów).  


### 2. Opisane czynności (tl;dr):

* zaawansowana instalacja QGIS-a za pomocą OSGeo4W,  
* instalacja i konfiguracja postgresql z rozszerzeniem postgis,  
* konfiguracja połączenia z bazą,  
* wczytanie załączonego projektu i skopiowanie warstw z projektu do bazy,
* konfiguracja triggerów. Część 1: podstawowe relacje pomiędzy warstwami,  
* konfiguracja relacji za pomocą właściwości atrybutów,
* pobranie i obróbka danych z zewnętrznej bazy (na przykładzie [LMS-plus](https://github.com/chilek/lms-plus)).


### 3. Wymagania (prawdopodobnie projekt zadziała na wcześniejszych wersjach, ale został utworzony i przetestowany na następujących):

* QGIS v. 3.30.2-'s-Hertogenbosch,  
* Rocky Linux 9.2,  
* postgresql v. 15+,  
* postgis v. 3.3.2.  


## II. Spis treści
[1. Instalacja QGIS-a za pomocą OSGeo4W](./doc/pl/1.%20Zaawansowana%20instalacja%20QGISa.md)  
[2. Instalacja i konfiguracja postgresql z rozszerzeniem postgis](./doc/pl/2.%20Instalacja%20i%20konfiguracja%20postgresql%20z%20rozszerzeniem%20postgis.md)  
[3. Konfiguracja połączenia z bazą](./doc/pl/3.%20Konfiguracja%20połączenia%20z%20bazą.md)  
[4. Wczytanie załączonego projektu i skopiowanie warstw z projektu do bazy](./doc/pl/4.%20Wczytanie%20załączonego%20projektu%20i%20skopiowanie%20warstw%20z%20projektu%20do%20bazy.md)  
[5. Konfiguracja triggerów. Część 1: podstawowe relacje pomiędzy warstwami](./doc/pl/5.%20Konfiguracja%20triggerów.%20Część%201%20-%20podstawowe%20relacje%20pomiędzy%20warstwami.md)  
[6. Konfiguracja relacji za pomocą właściwości atrybutów](./doc/pl/6.%20Konfiguracja%20relacji%20za%20pomocą%20właściwości%20atrybutów.md)  
[7. Pobranie i obróbka danych z zewnętrznej bazy](./doc/pl/7.%20Pobranie%20i%20obróbka%20danych%20z%20zewnętrznej%20bazy.md)  


## III. Dodatkowe informacje:  

### 1. Notka odnośnie relacji. Testowałem:  
- tabele z kolumną geometrii tworzoną jako "GENERATED ALWAYS AS (ST_Point(pe10_dlugosc, pe09_szerokosc, 4326))" - nie działają w QGIS-ie,
  problemem jest sam QGIS (powinien pomijać kolumnę generowaną w komunikacji z bazą - a akurat w przypadku geometrii nie pomija),
- relacje między tabelami przy użyciu "kluczy obcych" (tak to się tłumaczy?) - czyli "postgresql foreign keys". Kiepsko to działa
  w tym konkretnym projekcie oraz **i tak wymaga dodatkowych triggerrów**, więc skoro i tak musiałem pisać triggery - z foreign keys
  kompletnie zrezygnowałem

### 2. Notka odnośnie pobierania danych w QGIS-ie z zewnętrznych baz danych:
QGIS <b>bardzo słabo</b> radzi sobie z bardziej skomplikowanymi zapytaniami do baz zewnętrznych. ALE - jest rozwiązanie i na to. Zamiast w QGISIE używać np.:

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
wystarczy **w naszej bazie źródłowej** utworzyć widok (VIEW):
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

i teraz zapytanie ```SELECT * FROM vtable_for_qgis;``` w QGIS-ie zwróci nam wynik w pół sekundy.

## IV. Na koniec:

Projekt do otwarcia w QGIS-ie i dalszej zabawy [znajduje się tutaj](https://github.com/mrd83/qgis_with_postgis_database_example/releases)

Mam nadzieję że komuś się te informacje przydadzą.  

Pozdrawiam i powodzenia! :]  

-----------------------------------------------------------------------------------------------------------------------
  
  
  
English version soon to come...