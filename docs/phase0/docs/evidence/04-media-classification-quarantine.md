# Phase 0 media classification and quarantine report

## Document status

| Field | Value |
|---|---|
| Overall status | **DIRECTLY VERIFIED — CLASSIFICATION COMPLETE** |
| JPEG objects byte/visually inspected | 26/26 |
| PNG objects byte/visually inspected | 4/4 |
| PDF objects structurally/bounded-content inspected | 1/1 |
| AI/provider transmissions performed | 0 |
| Private images copied into repositories | 0 |
| Importable image bytes approved | 0 |

This report records only repository-safe hashes, dimensions, classifications,
and dispositions. The original images remain in the protected handover
evidence location and were not uploaded, transmitted, or copied into a
repository. Filenames are UUID-like identifiers and do not drive
classification.

## Verified classification totals

| Class | Count | Sensitivity | Disposition |
|---|---:|---|---|
| `STOCK_PHOTO` | 18 | High, home-private | Evidence/quarantine only; no repository or fixture copy |
| `GROCERY_RECEIPT` | 4 | High, home-private | Evidence/quarantine only; structured exports are the migration source |
| `MEDICAL_DOCUMENT` | 4 | Special/sensitive | **Rejected from grocery import; never transmit to AI** |
| `DESIGN_REFERENCE` | 4 | Low/medium evidence | Design evidence only; no operational import |
| `SPREADSHEET_PRINT_EXPORT` | 1 PDF | Private lineage | Pages 1–9 lineage only; do not bulk OCR |

All 30 JPEG/PNG files decode successfully. Byte detection reports
`image/jpeg` for every `.jpeg` file and `image/png` for every `.png` file.

## Private operational-media ledger

The following source paths are relative to
`04_original_project_files/Stock control/`. Full SHA-256 values bind each
classification to exact bytes.

| Filename | SHA-256 | Dimensions | Visual classification | Disposition |
|---|---|---:|---|---|
| `2338D12F-4E79-4F70-85A9-4373435EFB5E.jpeg` | `9fcdb6f3d2756b2311a45b4efff2bcd53f2dee9f3a57fdd3b9e21fa1cf86a717` | 1152×1536 | Stock shelf: boxed breakfast/dry goods | `STOCK_PHOTO`; private quarantine |
| `3D0C0F11-C121-496A-B3C0-01580311A327.jpeg` | `3e084bfcd79e391571751f8a229f6a57f65ef8754158bc87f8f4ec4830db8123` | 1536×1152 | Stock shelf: bulk pasta and condiments | `STOCK_PHOTO`; private quarantine |
| `48B6017F-F88F-4E2D-994E-679028401E1E.jpeg` | `b9aa83b46a6e12cb713a588ea7942db33bcdacf7ca779a6694434bb422bf1b45` | 1536×1152 | Stock shelf: tea, coffee, oil, and condiments | `STOCK_PHOTO`; private quarantine |
| `4C3B4A97-DE6D-487B-95C0-C62E0D04F3A2.jpeg` | `27e33411159d1c2732347a3a7a59c95e46f5d13bf1e5e654f14fea135a9664c9` | 1152×1536 | Grocery till receipt | `GROCERY_RECEIPT`; private quarantine |
| `51ABA6DA-570B-42FE-BD45-E9B12609E155.jpeg` | `44207d22a940d5b3b1d613b3313c147fc071bcfce5d194ade65633cc31cb597f` | 1536×1152 | Stock shelf: mixed canned goods | `STOCK_PHOTO`; private quarantine |
| `55217414-B4E8-4527-9430-7FFF649CB1AF.jpeg` | `1ebe29a6ee5ae0a33beb7ff6285b531de9170e81ee86fc2c6e457baadf4009ef` | 1536×1152 | Stock shelf: canned goods close view | `STOCK_PHOTO`; private quarantine |
| `5A2D2E29-94D6-4A52-A581-5AD7B0DF4DB8.jpeg` | `cb29d580ce9832d74ad7afddaadfe62a37d2daaa3c7c6f14c4dcdcae1cd31ffc` | 1152×1536 | Stock shelf: canned creamstyle corn | `STOCK_PHOTO`; private quarantine |
| `7C77405D-2FBD-4018-A198-1539078A33D6.jpeg` | `1830f6a10252f5dcbb302baf8cef5596d4df9811b8045ae000eb16ae229e8db0` | 1536×1152 | Household-cleaning stock shelf | `STOCK_PHOTO`; private quarantine |
| `A7513FA1-CF80-4EF5-90BF-D6C94C5348FD.jpeg` | `0277600accd288152de0ed4ce2fb5554d47b8773ce1e79305c1273839f95a6df` | 1536×1152 | Stock shelf: stacked pasta | `STOCK_PHOTO`; private quarantine |
| `A79FC34A-2AA6-4046-9C39-425426779350.jpeg` | `76c4d952d1ae73bd69bd8ead83d58f451e7ca2e7c3b9133ca666c947f458ee4c` | 1152×1536 | Grocery till receipt | `GROCERY_RECEIPT`; private quarantine |
| `A7B14E83-CD1A-425E-805F-CD9D47E3935C.jpeg` | `5c1f75e94f3c7f6b3ed19681147e41b1c952e4d8387e619846cd47a9cea38c63` | 1536×1152 | Household-cleaning bulk stock | `STOCK_PHOTO`; private quarantine |
| `AF7D0B4E-6273-475B-84E3-1EE437103AFB.jpeg` | `dbd70e2d3fae933135c2e9e22de4287770e25c73b68dfe8ddf0192da75c44c2a` | 1536×1152 | Stock shelf: mixed pasta close view | `STOCK_PHOTO`; private quarantine |
| `B23297D0-808E-4EFF-AD16-3BD2DEFEA98B.jpeg` | `0c614aebcaba47420219be0867ad4bd9fc8bd3ed9dcb361d6b5c1862e5a9f2fe` | 1152×1536 | Wider stock shelving overview | `STOCK_PHOTO`; private quarantine |
| `C2AF762A-9D56-49EF-84FC-72074B56C5AC.jpeg` | `be3d43e07ef5cb54e1ade83ee7230c042e8765174763b3df0f1e67cb37494435` | 1152×1536 | Grocery till receipt | `GROCERY_RECEIPT`; private quarantine |
| `C4A06347-AFDE-4619-ADF5-09B61EC08519.jpeg` | `7fc0006f70ab8b6f8272cb035fd6aa23b229ffba93fec1e432c84b54aece097b` | 1536×1152 | Stock shelf: bulk flour | `STOCK_PHOTO`; private quarantine |
| `C7DF4532-C244-416D-9AE4-4ADED1B0AC41.jpeg` | `442dda386c84e5a8fefbeba48b320e9b0bc5a3000ad2879c9c9c04afc742eda2` | 1152×1536 | Grocery till receipt | `GROCERY_RECEIPT`; private quarantine |
| `DB619F15-2273-48D0-93CF-7A5DE47B3C3F.jpeg` | `559e93de1da3825b295c6394babb1e9cc4aa73306e898f4c1c3e62ec6d665660` | 1536×1152 | Stock shelf: mixed pasta | `STOCK_PHOTO`; private quarantine |
| `E5611970-768F-47E5-B58B-5587173886B6.jpeg` | `47184cf90a7afab76cd25776544674db6f15eeba70331a726500f7aeb7b09bae` | 1536×1152 | Stock shelf: beverages/concentrates | `STOCK_PHOTO`; private quarantine |
| `EDD6B3F3-FD7B-471F-B65B-860793C0043A.jpeg` | `47f23f5876d49c62e2bdb10b10d68b185d8661fbad77e2ef000379788ef92ba2` | 1536×1152 | Stock shelf: rice and flour | `STOCK_PHOTO`; private quarantine |
| `F35A1C2C-C07A-46B1-BC0C-D9B85D2EFEC0.jpeg` | `f80fbfacf96bc226d54240a5419407795564588169cec9bbea70a7a38f10fcb6` | 1536×1152 | Stock shelf: pasta | `STOCK_PHOTO`; private quarantine |
| `F915253E-AA1A-497D-B986-F5CD6D6984AC.jpeg` | `9686cad566e1cccae8b03a4067b25b68237295f0dcb5847c22ec7021572d12e3` | 1536×1152 | Stock shelf: pasta alternate angle | `STOCK_PHOTO`; private quarantine |
| `FE8F0EB9-0929-42C5-A274-66DE40B39A0C.jpeg` | `b85bb8a889ab44a1ede55cbc535484d9b0d05e1b1e8b0347f24bd9196893319d` | 1536×1152 | Stock shelf: canned goods wider view | `STOCK_PHOTO`; private quarantine |

This directory therefore contains **18 stock photos and four grocery
receipts**. The folder name alone would have misclassified the receipt images.

## Restricted medical filename record

**Private evidence: do not copy this section into public documentation, CI
logs, issues, support exports, or marketing material.**

The four files under `04_original_project_files/Receipt photos/` were visually
confirmed as medicine-information leaflet pages, not grocery receipts:

| Exact filename | SHA-256 | Dimensions | Disposition |
|---|---|---:|---|
| `18921C1A-73F4-4AD7-BAF6-47C23CBD8B8E.jpeg` | `565f7f78faed03abb6cd4d2429d1fbc3a4dc703e537f703bd2d195edb5aade9e` | 1152×1536 | `MEDICAL_DOCUMENT`; reject and retain only in restricted source evidence pending owner disposition |
| `AA3872D9-34D5-422E-944F-F959EC6C3EA0.jpeg` | `a1a47d1156834311732e01153e0b38e061fd7e7dab82a6c337cf3f1da79a4543` | 1152×1536 | `MEDICAL_DOCUMENT`; reject and retain only in restricted source evidence pending owner disposition |
| `B2D90CCA-5E4A-4106-90CB-130E2775828A.jpeg` | `4221387c5bd280bdacd2822fe8a14b58ba748943a4b9193b785a3855ced18a54` | 1152×1536 | `MEDICAL_DOCUMENT`; reject and retain only in restricted source evidence pending owner disposition |
| `EDD62B42-0883-4135-9481-397056901256.jpeg` | `f09ff4bd103e984810fb1e5edcdf007ef4b80f8ba2301674dbac1f004f84c35a` | 1152×1536 | `MEDICAL_DOCUMENT`; reject and retain only in restricted source evidence pending owner disposition |

They must never:

- be sent to any AI/OCR/vision provider;
- be imported as receipts;
- be copied into either application repository;
- be retained in production fixtures;
- appear as screenshots, previews, logs, support attachments, or test
  artifacts.

## Design/reference PNG ledger

Paths are relative to `05_design_and_review/`.

| Filename | SHA-256 | Dimensions | Visual classification | Disposition |
|---|---|---:|---|---|
| `category-review-preview.png` | `0e4023048da785fec69562c6ccec432e21361d9cdac924614787d65ebf9ea598` | 2849×977 | Category-review workbook preview | `DESIGN_REFERENCE`; evidence only |
| `consolidation-instructions-preview.png` | `fc03be2629a635f16e2728c01acb9b1f3b08caa7e1ea56779f15c647f07d4328` | 1330×623 | Consolidation instruction-sheet preview | `DESIGN_REFERENCE`; evidence only |
| `fresh-market-selected-direction.png` | `b87ec1fcbc7c466858d6d7459e8ee17f665888f6626b33889c4c72935a0198cd` | 1586×992 | Selected Fresh Market phone dashboard direction | `DESIGN_REFERENCE`; approved visual evidence |
| `match-review-preview.png` | `18227714b7192cf7694fce3d4f0cc0e3bc99035b6c887a6baa448f0ea53f88b7` | 4051×1165 | Product-match review workbook preview | `DESIGN_REFERENCE`; evidence only |

The selected visual confirms a warm cream canvas, dark forest-green headings,
fresh-green primary actions, compact recent-item rows, rounded touch controls,
soft panel shadows, orange low-stock warning, and four-item bottom navigation.
The displayed counts belong to the earlier visual concept; data totals must
come from the verified current exports.

## `Shopping 2026.pdf`

| Property | Verified value |
|---|---|
| SHA-256 | `b54338cb20df45eb23d27a4eb049fb379790105b6f95d9897f403cec09185dc9` |
| MIME/type | Unencrypted PDF 1.7 |
| Page count | 586 |
| Page size | A4 |
| Pages 1–9 | Purchase-log data visible |
| Page 10 | Repeated blank/placeholder `x` print-range output |
| Sampled page 100 | Same placeholder-noise pattern |
| Page 586 | Repeated `#DIV/0!` output |

Accepted handling:

- use the verified 452-line CSV as the operational migration source;
- use pages 1–9 only for lineage checks;
- never OCR all 586 pages;
- never create transactions from later-page placeholders or errors.

## Production classification controls

1. Every media object starts in quarantine.
2. Extension, filename, and folder are hints only.
3. Verify digest, detected MIME, safe decode, dimensions/page count,
   sensitivity, visual class, and disposition before processing.
4. Use synthetic/redacted fixtures in development and CI.
5. Keep original receipt and stock images local to the originating device by
   default.
6. Strip unnecessary EXIF from any authorized working copy.
7. Require a user preview, provider/privacy display, and explicit transmission
   confirmation.
8. State truthfully when cloud processing makes media leave the device.
9. Store validated structured results by default, not original media.
10. Keep optional encrypted private-media backup disabled until separately
    approved with retention/deletion controls.

## Phase 0 gate

The media evidence gate is complete because every supplied JPEG/PNG has:

- a verified package/object checksum;
- a detected byte type and successful decoder result;
- dimensions;
- a visual classification;
- a privacy class;
- a documented disposition.

This classification does **not** authorize operational image import. Real image
bytes remain excluded from repositories, fixtures, automated tests, and AI
providers.
