# bootstrap_ref_customers v2 — Report
Generated: 2026-06-07 21:34:10  |  Mode: **--apply**

## Summary

| Category | Count |
|----------|-------|
| CRM rows (ref = bc_customer_no) | 2678 |
| — of which NE PLUS UTILISER (is_active=0) | 68 |
| — with email | 1275 |
| — with trade_channel (from sheet enrichment) | 151 |
| — off_trade | 0 |
| — on_trade | 151 |
| Sheet enrichment matches (trade_channel set) | 154 |
| — parens-ID resolved by CRM | 108 |
| Sheet-only inserts (needs_review=1) | 368 |
| Sheet-only collisions SKIPPED | 47 |
| Sheet exclusions | 52 |
| Sheet parens-ID unmatched (not in CRM) | 0 |
| **Total rows to INSERT** | **3046** |

## Validation — inv_sales_bc vs CRM

inv_sales_bc distinct customer_no: 270
Missing from CRM export: 0

_All inv_sales_bc customer_nos are present in CRM export._

## Sheet Enrichment — Matched by Parens-ID

| Sheet Name → BC No | BC/CRM Name | trade_channel |
|--------------------|-------------|---------------|
| 3662 | Celebration Food Service Sàrl | on_trade |
| 3746 | Mingard boucherie et alimentation SA | on_trade |
| 2325 | Bar le Charlot | on_trade |
| 3938 | Les Epicuriens de Lully | on_trade |
| 3872 | Association Les Jardins de Louis | on_trade |
| 1045 | Apothibières-Martigny | NULL |
| 3829 | Le Dragon Éméché | NULL |
| 3709 | Le Castel de Bois Genoud | on_trade |
| 3941 | TRIGO - SGM Food Sàrl | on_trade |
| 3539 | Epicerie du Village | on_trade |
| 1327 | Pavillon Bar & Kitchen | on_trade |
| 3903 | Diff Bistro | on_trade |
| 3807 | Associaiton Blues en scène | on_trade |
| 3598 | Numa Supply | on_trade |
| 3129 | Association Rafro Léman | on_trade |
| 3519 | Europrisme Médical Suisse Sàrl c/o LPG Genève, Fiduciaire de Suisse Sàrl | on_trade |
| 3740 | Marché Flag Aubonne Sàrl (Migros Partenaire Aubonne) | on_trade |
| 3943 | AXA Agence Générale Michaël Gil | on_trade |
| 3859 | Giron Coinsins 26 | on_trade |
| 3942 | Atelier Ensemble | on_trade |
| 3052 | Stones Family | on_trade |
| 2467 | GAF Groupe d'animation de Florissant | on_trade |
| 3615 | Beer O'Clock | on_trade |
| 1581 | Bachibouzouk, le Café Colin et Cie | on_trade |
| 1984 | La Route des Vins, Guyot | on_trade |
| 3273 | Black Movie Festival | on_trade |
| 2657 | Ville de La Tour-de-Peilz - Service Sport et Jeunesse | on_trade |
| 1192 | Palexpo S.A | on_trade |
| 1275 | Particules en Suspension | on_trade |
| 3808 | Association le Satellite Sierre | on_trade |
| 3452 | Fishermen's Pub Sàrl | on_trade |
| 3838 | Fondation Clémence | on_trade |
| 3390 | HOLINGER AG | on_trade |
| 3579 | Edel Café SNC | on_trade |
| 2502 | Cabane Mont Fort | on_trade |
| 3375 | Section Vaudoise Zofingue | on_trade |
| 3136 | Association Big-T SoundSystem | on_trade |
| 3663 | SkiClub Rolle | on_trade |
| 3816 | Patinoire de Lutry | on_trade |
| 2739 | Société d'étudiants de Belles-Lettres Théâtre du Lapin Vert | on_trade |
| 2943 | Central Camps | on_trade |
| 2501 | Le Koti SA | on_trade |
| 3786 | Stack Food Sàrl (Sheesh) | on_trade |
| 3821 | Association Préverenges800 | on_trade |
| 3557 | Pschitt SA | on_trade |
| 3862 | Atmoce | on_trade |
| 1793 | AESSP- Work Choppe | on_trade |
| 3139 | Origens Restaurant / Festival BBQ | on_trade |
| 1659 | EPFL | on_trade |
| 3869 | Théâtre de la dernière minute | on_trade |
| 3868 | Association Maximus Discotecus | on_trade |
| 3371 | Lustriacum | on_trade |
| 3791 | Manzoni Event / Tataki Awards | on_trade |
| 2638 | Station Rock Café | on_trade |
| 3032 | Shapes Events Switzerland | on_trade |
| 3017 | TLML SA - Télé Leysin-Col des Mosses-La Lécherette SA | on_trade |
| 2361 | Tobie catering Management (Le Rouge Verbier) | on_trade |
| 3888 | Camping Sedunum SA | on_trade |
| 3536 | Pepitium SA | on_trade |
| 3031 | Sport Santé UNIL + EPFL | on_trade |
| 3873 | Jeunesse de Begnins | on_trade |
| 3878 | La Bretelle Bar Associatif | on_trade |
| 3454 | Pétanque Renanaise | on_trade |
| 2363 | FWT Management SA | on_trade |
| 3892 | Papa's Kitchen SNC | on_trade |
| 3887 | JJH Cuisines Diffusion SA | on_trade |
| 2291 | Association Festi’Cheyres | on_trade |
| 3895 | Ascenseurs Schindler SA | on_trade |
| 3502 | Bujard Vin | on_trade |
| 3898 | Association CLIC (EPFL) | on_trade |
| 3607 | L'AVOINE, Association Vaudoise des Observateurs Indépendants de la Nature Etendue | on_trade |
| 3834 | Les Traîne-Savates | on_trade |
| 2983 | Centre sportif de Malley SA \| Vaudoise aréna | on_trade |
| 3907 | Delley Boissons Sàrl | on_trade |
| 2090 | APCL - Service des Sports | on_trade |
| 3501 | Forum EPFL | on_trade |
| 3492 | Budokan-Zürich | on_trade |
| 3054 | EPFL VPA AVP CP CMi | on_trade |
| 3152 | Fine Artisans Group - Artisans de la Vaudaire Sàrl | on_trade |
| 3910 | Bains Payes Sàrl | on_trade |
| 3908 | La Ruche | on_trade |
| 2333 | Les Gosses du Québec LS | on_trade |
| 3565 | Eps Prilly | on_trade |
| 3914 | EPFL Racing Team | on_trade |
| 3196 | Bambino Sàrl | on_trade |
| 3270 | Le Bateau Rouge Pub | on_trade |
| 3912 | Junior Entreprise EPFL | on_trade |
| 3911 | Chœur des Dames des Renens | on_trade |
| 3089 | LÜMM | on_trade |
| 2097 | L'Oiseau Moqueur Sàrl (Terrasse des Grandes Roches) | NULL |
| 3906 | Charbon torrefaction | on_trade |
| 3741 | Migros Partenaire - Bière | on_trade |
| 3745 | Migros Partenaire - Thierrens | on_trade |
| 1075 | Théâtre Boulimie | on_trade |
| 1182 | Asar/Map 15 | on_trade |
| 3641 | Association Wine Night Festival | on_trade |
| 3482 | Smok'ed | on_trade |
| 3224 | Roundnet Lausanne | on_trade |
| 3656 | HC Kangaroos | on_trade |
| 2937 | LAB - Ass. Etudiants en Biologie | on_trade |
| 2902 | Trois Roises Sàrl - Proxi Alimentation Générale | on_trade |
| 3937 | Yoann Chapel | on_trade |
| 3114 | HETSL - Haute Ecole de Travail Social et Santé | on_trade |
| 3567 | Les trésors de l'Ellinga | on_trade |
| 3522 | Zero Emission Group | on_trade |
| 3486 | Association Corinne Jayet | on_trade |
| 2323 | Festival Artiphys | on_trade |
| 3226 | Ballons du Léman | on_trade |

## Sheet Enrichment — Matched by Exact Normalised Name

Count: 46

## Sheet-Only Inserts (top 50 / total=368)

| Sheet Name | is_private | trade_channel |
|------------|------------|---------------|
| Doki Doki | 0 | on_trade |
| De Sieb Romanel | 0 | on_trade |
| NAU | 0 | on_trade |
| Alloboissons | 0 | on_trade |
| Stardrinks | 0 | on_trade |
| Bevanar | 0 | on_trade |
| Tip Top Drinks | 0 | on_trade |
| Stock Cobra | 0 | off_trade |
| Jardins de Louis | 0 | on_trade |
| Café Simplon | 0 | on_trade |
| Wynwood | 0 | on_trade |
| Lacustre | 0 | on_trade |
| Manor Rickenbach | 0 | off_trade |
| Gaf 2467 | 0 | on_trade |
| Blavignac | 0 | on_trade |
| Quéruel | 0 | on_trade |
| MonDrink | 0 | on_trade |
| Louis Cardis. échantillons | 0 | on_trade |
| Louis C. Privé | 1 | on_trade |
| Dorian F. Privé | 1 | on_trade |
| Laure Szalai (Privée) | 1 | on_trade |
| Arches | 0 | on_trade |
| Jetée | 0 | on_trade |
| Max Boissons | 0 | on_trade |
| Nausikraft | 0 | on_trade |
| Taprrom | 0 | on_trade |
| COOP Aclens | 0 | off_trade |
| Didier Falconnet (privé) | 1 | on_trade |
| Maxime Hedou(Privée) | 1 | on_trade |
| COOP Pratteln | 0 | off_trade |
| Section Ouest | 0 | on_trade |
| Yada Lausanne | 0 | on_trade |
| Qoqa food | 0 | on_trade |
| Magali Mancianti ( privé) | 1 | on_trade |
| Bayclub | 0 | on_trade |
| Grain d'Orge | 0 | on_trade |
| De Sieb | 0 | on_trade |
| Tour du Mont Blanc (Marketing) | 0 | on_trade |
| Ganesh Store | 0 | on_trade |
| RSH | 0 | on_trade |
| Gotham (Marketing) | 0 | NULL |
| Aligro GE | 0 | off_trade |
| Aligro CH | 0 | off_trade |
| 1148 Théâtre Kléber-Mélea | 0 | on_trade |
| Mobilet | 0 | on_trade |
| Gotham | 0 | NULL |
| SVAG Conseil en Patrimoine | 0 | on_trade |
| Cité (1823), Courtine | 0 | on_trade |
| Cité (1823), Grand Canyon | 0 | on_trade |
| Cité (1823), Pierre Viret | 0 | on_trade |

## Sheet-Only Collisions (SKIPPED — likely missed normalisation)

| Sheet Name | Conflicting CRM BC No |
|------------|----------------------|
| Louis c. Privé | sheet-only:Louis C. Privé |
| Coop Pratteln | sheet-only:COOP Pratteln |
| Pack Dec | sheet-only:Pack dec |
| Tip top Drinks | sheet-only:Tip Top Drinks |
| Section ouest | sheet-only:Section Ouest |
| pointu | sheet-only:Pointu |
| Casa Nour | sheet-only:casa nour |
| Café du Simplon | sheet-only:café du simplon |
| QoQa Food | sheet-only:Qoqa food |
| Le Pointu | sheet-only:le pointu |
| Coop Aclens | sheet-only:COOP Aclens |
| De sib romanel | sheet-only:De sib Romanel |
| Nau | sheet-only:NAU |
| Orif Tilleuls | sheet-only:Orif tilleuls |
| Migros ouchy | sheet-only:Migros Ouchy |
| Zao cafe | sheet-only:Zao Café |
| De sieb Romanel | sheet-only:De Sieb Romanel |
| Renens vipers | sheet-only:Renens Vipers |
| échantillons Dorian | sheet-only:Echantillons Dorian |
| Docks Loges | sheet-only:Docks loges |
| petanque tunnel | sheet-only:Pétanque tunnel |
| De sieb yverdon | sheet-only:De Sieb Yverdon |
| Le pointu | sheet-only:le pointu |
| Doki doki | sheet-only:Doki Doki |
| Tip Top drinks | sheet-only:Tip Top Drinks |
| Mondrink | sheet-only:MonDrink |
| qoqa food | sheet-only:Qoqa food |
| Jetee | sheet-only:Jetée |
| zao cafe | sheet-only:Zao Café |
| De Sieb romanel | sheet-only:De Sieb Romanel |
| Stock cobra | sheet-only:Stock Cobra |
| Boissons service | sheet-only:Boissons Service |
| pack Dec | sheet-only:Pack dec |
| Castel de bois genoud | sheet-only:Castel de Bois Genoud |
| Queruel | sheet-only:Quéruel |
| DE sieb Romanel | sheet-only:De Sieb Romanel |
| La Rincette | sheet-only:La rincette |
| Casa nour | sheet-only:casa nour |
| Docks bar | sheet-only:Docks Bar |
| simplon | sheet-only:Simplon |
| Pétanque Tunnel | sheet-only:Pétanque tunnel |
| wynwood | sheet-only:Wynwood |
| De sib Yverdon | sheet-only:DE sib yverdon |
| jetée | sheet-only:Jetée |
| Casanour | sheet-only:casanour |
| Quéruel Boissons | sheet-only:Quéruel boissons |
| jardins de louis | sheet-only:Jardins de Louis |

## Sheet Exclusions

| Sheet Name | Reason |
|------------|--------|
| # Semaines de Stock | header-artifact |
| Taproom | internal-channel |
| Eshop | internal-channel |
| Cage | internal-channel |
| Shop | internal-channel |
| 3776 | numeric-only |
| 3777 | numeric-only |
| 3656 | numeric-only |
| 3500 | numeric-only |
| shop | internal-channel |
| 3786 | numeric-only |
| 2943 | numeric-only |
| cage | internal-channel |
| 3363 | numeric-only |
| 3758 | numeric-only |
| 3006 | numeric-only |
| 2800 | numeric-only |
| 3746 | numeric-only |
| 1036 | numeric-only |
| 1966 | numeric-only |
| 3611 | numeric-only |
| 2778 | numeric-only |
| 3718 | numeric-only |
| 2981 | numeric-only |
| 3840 | numeric-only |
| 3319 | numeric-only |
| 2376 | numeric-only |
| 3454 | numeric-only |
| Shop neb | internal-channel |
| 3567 | numeric-only |
| 1085 | numeric-only |
| 3845 | numeric-only |
| 3835 | numeric-only |
| EShop | internal-channel |
| 2934 | numeric-only |
| 3867 | numeric-only |
| 3868 | numeric-only |
| 1045 | numeric-only |
| 2009 | numeric-only |
| taproom | internal-channel |
| KP | too-short |
| 2958 | numeric-only |
| 3901 | numeric-only |
| 3904 | numeric-only |
| 1275 | numeric-only |
| eshop | internal-channel |
| 2291 | numeric-only |
| 2883 | numeric-only |
| Shop Mon repos | internal-channel |
| 1996 | numeric-only |
| 3031 | numeric-only |
| 2692 | numeric-only |

