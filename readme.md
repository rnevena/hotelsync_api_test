uputstvo za pokretanje:

1. projekat je rađen na localhost-u uz pomoć xampp-a za pokretanje lokalnog apache servera
2. potrebno je prevući folder hotelsync_api u htdocs folder u okviru xampp aplikacionog foldera
3. u okviru fajla sql/dbscript.txt se nalazi sql skripta za kreiranje baze sa pratećim tabelama, potrebno pokrenuti skriptu na localhostu (phpmyadmin)
4. pošto se skripte po uputstvu iz taskova pokreću iz terminala, pre pokretanja potrebno je pozicionirati se putem terminala u folder hotelsync_api/scripts // prilikom testiranja je korišćen integrisani terminal u okviru VSCode editora
5. za pokretanje webhook-a za task #5 je kreiran POST endpoint koji se može pokrenuti putem Postmana. Nalazi se u Postman kolekciji hotelsync_api.postman_collection.json pod nazivom 'webhook'. ima u sebi već pripremljena dva example-a sa različitim body u request-u, zavisno od toga da li je insert ili update/cancel u pitanju // u okviru kolekcije se pored ovoga nalaze i neki od servisa koje sam koristila za taskove 

> [!IMPORTANT] bitne informacije:

1. zadaci su postavljeni tako da se svi podaci povlače sa naloga u demo aplikaciji https://app.otasync.me/ koji je kreiran u cilju testiranja.
odnosno, prilikom pokretanja skripti, podaci će se povlačiti sa mog demo naloga. kredencijali i token su konfigurisani u okviru config/config.php
2. *za task 2 - reservation import koristiti opseg datuma --from=2026-03-01 --to=2026-03-31 jer su demo rezervacije kreirane u trenutku otvaranja demo naloga ( od 12. marta )*

