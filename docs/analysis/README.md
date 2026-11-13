# MonteFiskal analysis package

Input for the coding session. It describes the Montenegrin electronic fiscalization service (EFI) as it is today, the rules an implementation must follow, what existing open-source code does and does not cover, and a proposed architecture. It contains **no code**, no certificates, no keys, no passwords and no real taxpayer data.

Analysis date: 2026-11-13. Language: English, except the summary at the end of this file.

## Contents

| File | What it is |
|---|---|
| `sources/README.md` | Every source with URL, access date, version, SHA-256 and retrieval status; the copy policy; what could not be retrieved or was not read |
| `message-catalog.json` | Machine-readable description of all three operations and six messages, generated from the official live WSDL and XSD: every element and attribute with XPath, type, cardinality, pattern, length, enumeration and schema description |
| `message-catalog.md` | The same catalog for people, plus 24 schema observations and the schema-drift table |
| `business-rules.md` | IKOF and JIKR, signature, QR URL, numbering, invoice kinds, payment methods, VAT, amounts, corrections, offline and late delivery, TCR, cash deposit, pre-send checks, printing, retention and inspector reports; every rule cited |
| `error-codes.md` | Wire format of faults, all published codes (0 to 58, 9xx) with original text, meaning, cause, retryability and action; handling of unknown codes |
| `gap-analysis.md` | OpenMicroFiscal compared line by line with MonteFiskal's scope; WooCommerce integration patterns from a Croatian plugin |
| `test-strategy.md` | Test layers, self-generated certificates, the shared `test-vectors/` format, vectors available from the specification, efitest conditions, test-certificate warning |
| `architecture.md` | .NET package layout and API sketch, mirrored PHP core, WooCommerce plugin design, versioned adapter layer, repository layout, CI, licences, community files |
| `ai-assistant-kit.md` | What the repository ships for coding assistants (Claude Code, Cursor, Copilot, Codex): verified file formats, `AGENTS.md` / `CLAUDE.md` / Cursor rules / `llms.txt` drafts, the intake questions an assistant asks before integrating, twelve integration prompts, go-live checklist, acceptance criteria |
| `open-questions.md` | 29 questions with their status: why each matters, who can answer, the default until then, and a change log |

## Reading order

1. This file.
2. `open-questions.md`, section "Blocking for coding" (every entry now has an answer or an applied default; see `architecture.md` §9).
3. `business-rules.md` sections 3, 4, 6, 7 and 12.
4. `message-catalog.md`, "Schema observations" and "Schema drift"; use the JSON for generation.
5. `architecture.md`.
6. `test-strategy.md`, then `error-codes.md` and `gap-analysis.md` as reference.
7. `ai-assistant-kit.md`, before naming options and public API: the intake questions should map one-to-one to settings.

## How to read the evidence labels

A statement with a source citation and no label comes from a retrieved official document. **OBSERVED** means seen on the live service or schema on the analysis date. **UNVERIFIED** means the evidence is outdated, third-party or inferred; such rules must stay configurable and must not block issuing.

State of the sources: the current official set, technical and functional specification **v5** (2021), the examples annex and two rulebooks, was read on 2026-11-13 from the Tax Administration's page, which is not reachable from every network. Earlier rows were written from the 2020 versions (v02) and were updated where v5 differs. The live schema is slightly newer than v5 and always wins for structure. The law (including its 2021 amendment) and the current VAT law were read from other hosts; see `sources/README.md`.

## Consistency of the catalog

`message-catalog.json` was produced mechanically from the files whose digests it records (`meta.xsdSha256`, `meta.wsdlSha256`). Checks performed: 130 attribute declarations in the XSD equal 118 attributes in named types plus 12 root attributes in the catalog; 467 enumeration values in both; 35 simple types, 32 complex types, 6 global elements; three WSDL operations. The production XSD is byte-identical to the test XSD. The extraction script was temporary and is intentionally not part of the repository.

## Özet: en önemli 10 bulgu (Türkçe)

1. **Güncel resmî şartname (v5) okundu; canlı şema ondan da biraz yeni.** v5 (2021) fatura tiplerini (`InvType`), zorunlu ENU kodunu ve yeni ödeme yöntemlerini getirmiş; eski biçimler 1.10.2021'e kadar kabul edilmiş. Canlı XSD'de v5 metninde olmayan birkaç değer daha var. Üstelik uç nokta (`fs-v1`) ve `Version="1"` hiç değişmemiş. Katalog bu yüzden canlı XSD'den üretildi ve mimaride sürümlü adaptör katmanı şart.
2. **Yalnızca üç operasyon var:** `RegisterInvoice`, `RegisterTCR`, `RegisterCashDeposit`. İrsaliye (WTN) operasyonu yok. Test ve üretim XSD'leri bayt bayt aynı.
3. **IKOF algoritması doğrulandı.** Yedi alan `|` ile birleştirilir (PIB, düzenleme zamanı, sıra numarası, işyeri kodu, ENU kodu, yazılım kodu, toplam), RSA-SHA256 (PKCS#1 v1.5) ile imzalanır, imzanın MD5'i IKOF olur. Şartnamedeki örnek yeniden hesaplandı ve tuttu. Şartname metnindeki örnek dizide yazım hataları var; doğru girdi belgelerde kayıtlı.
4. **Her faturada ENU (TCR) kodu zorunlu ve bu artık kanunda da yazıyor.** 2021 değişikliğiyle fatura numarası nakit ve gayrinakdi için aynı oldu: sıra no, işyeri, yıl, ENU. Sıra numarası her yıl 1'den başlar ve ENU başına yürütülebilir. Web mağazası da bir ENU kaydetmek zorunda; eklentinin kurulum sihirbazı bunu yapmalı.
5. **Kartla ödeme "nakit" faturadır ve bu artık kesin.** v5'e göre gerçek kişiye ait banka kartı (`CARD`) ve nakit (`BANKNOTE`) yalnızca `CASH` faturada kullanılır; şirket kartı, havale, avans gayrinakdidir. Nakit fatura kesen ENU her gün ilk faturadan önce başlangıç depozitosu (0,00 olabilir) bildirmek zorunda ve yazılım bundan önce nakit fatura göndermeyi engellemeli (aksi hâlde hata 58). Eklentide günlük sıfır depozito işi çekirdek özellik oldu. Nakit fatura kâğıda basılıp alıcıya verilmek zorunda; gayrinakdi fatura alıcı onayıyla elektronik olabilir.
6. **Önce kes, sonra gönder.** Servise ulaşılamazsa fatura yalnızca IKOF ile kesilir ve aynı içerikle 48 saat içinde (kanunda "iki gün") yeniden gönderilir. Şema hatası, boyut aşımı, KDV durumu uyuşmazlığı ve geçersiz imza ise "bloklayıcı" hatadır; bu durumda fatura hiç kesilmemeli. Kabul edilen faturalar ayrıca gece toplu denetimden geçer; hatalı çıkan fatura SEP'te görünür ve 8 gün içinde `ERROR_CORRECTIVE` ile düzeltilmelidir. Bu yüzden yerel ön doğrulama ve kalıcı bir gönderim kuyruğu çekirdek gereksinim.
7. **Hata listesi eksik.** v5'te yayımlanmış kodlar 0–58 ve 9xx ile sınırlı (58 yeni, 40 kaldırılmış) ve şartname listenin tam olmadığını söylüyor. Canlı serviste hata, HTTP 500 içinde İngilizce metinli bir SOAP Fault olarak geliyor; sayısal kod `detail/code` içinde. Hata kataloğu kapalı bir enum değil, veri olmalı.
8. **Ortada dolaşan test sertifikası kullanılmayacak.** OpenMicroFiscal ve bir NodeJS örneğindeki `.pfx` aynı dosya; özel bir şirketin CA'sından çıkmış, Vergi İdaresi'nin yayımladığına dair kanıt yok ve 2023'te süresi dolmuş. Uçtan uca test için kendi sertifikamız ve test portalında kendi kaydımız gerekiyor. Depoya hiçbir sertifika, anahtar ya da parola girmedi.
9. **OpenMicroFiscal dar bir dilimi kapsıyor.** Yalnızca fatura gönderimi; her zaman gayrinakdi, her zaman KDV mükellefi, iki ödeme yöntemi. ENU kaydı, kasa depozitosu, çevrimdışı kuyruk, yeniden gönderim, tipli hatalar, yanıt imzası doğrulama, XSD doğrulama, QR görseli, arşiv ve denetçi raporları yok. Yine de üretimde çalıştığı için bazı davranışları (yuvarlama, `mapr.tax.gov.me` doğrulama adresi) değerli kanıt.
10. **PHP tarafında iki teknik tuzak var.** Resmî XSD'deki iki desen (`\/` kaçışı) libxml2'de derlenmiyor, yani PHP şemayı olduğu gibi doğrulayamıyor; düzeltilmiş kopya ya da katalogdan üretilmiş doğrulayıcı gerekiyor. Ayrıca eski tip `.pfx` dosyaları OpenSSL 3 ile açılmıyor; bunu algılayıp kullanıcıya açık bir mesaj göstermek gerekiyor.

Sonradan eklenen bulgular (ikinci arama turu): KDV oranları 21, 7, 15 ve 0 (güncel KDV kanunu); saklama süresi yasal olarak en az 5 yıl; yazılım kodu için SSS'ye göre yazılımı kullanan şirket kaydı kendisi yapabiliyor, ancak işlevi değiştiren her güncelleme yeniden kayıt ve yeni kod gerektiriyor (Q-26). Bu, açık kaynak dağıtım modelini doğrudan etkiliyor.

Kodlamayı bloklayan sorular ve varsayılan kabuller `open-questions.md` dosyasının ilk bölümünde.

Üçüncü tur (resmî sayfa okunduktan sonra): avans akışı resmî olarak tanımlı (ödeme anında avans faturası, teslimde `ADVANCE` ödeme yöntemli ve avans IKOF'una atıflı fatura, ardından avansı azaltan düzeltme faturası); ön ödemeli web siparişinin avans sayılıp sayılmadığı ise hâlâ muhasebeciye sorulacak hukuki bir soru (Q-24). Yazılım kodu konusunda resmî metinler yalnızca üretici ve bakımcının on karakterlik bir tanıtım koduna sahip olması gerektiğini söylüyor; "kullanan şirket kendi kaydedebilir" ve "her işlevsel güncellemede yeni kod" bilgileri hâlâ yalnızca üçüncü taraf SSS kopyasına dayanıyor (Q-26). Yeni soru: ödeme geçidi kartın bireysel mi kurumsal mı olduğunu bildirmediği için `CARD` / `BUSINESSCARD` ayrımı nasıl yapılacak (Q-29). QR kod en az 21 × 21 mm basılmalı; ENU ilk faturadan en az bir saat önce kaydedilmeli.

Dördüncü tur (masa başı araştırma, "muhasebeciye sor" yerine): Vergi İdaresi'nin kendi SSS belgesi, güncel kanun metni (30.03.2026), erişim talimatı v4.1, SEP kılavuzu ve mesaj yönetmeliğinin 10 eki okundu. Sonuçlar: 8/2021'den sonra kanun değişikliği yok; yazılımı kullanan ve sahibi olan şirket kaydı kendisi yapabiliyor, kod otomatik veriliyor, işlevi değiştiren her güncelleme yeni kod istiyor (Q-26, resmî); ön ödemeli web siparişi biçimsel olarak avans, ama web mağazaları için resmî bir açıklama yok, varsayılan "ödeme anında tek fatura", avans akışı seçenek (Q-24); kart türünü satıcı bilemediği için varsayılan `CARD` (Q-29, resmî olarak çözülmemiş); depoya hiçbir anahtar girmiyor (Q-19); resmî XSD ve WSDL değiştirilmeden depoya alınıyor, dayanak telif kanununun 8. maddesi (Q-20). Her yanıtın kaynağı ve güven düzeyi `open-questions.md` içinde.

Beşinci tur: yapay zekâ asistanı kiti eklendi (`ai-assistant-kit.md`). Depoda `AGENTS.md`, `CLAUDE.md`, Cursor kuralları ve `llms.txt` yer alacak; entegrasyon yapan geliştirici için asistanın önce sorması gereken 21 soru, 12 hazır prompt ve canlıya geçiş kontrol listesi tanımlandı. Dosya biçimleri araçların kendi dokümanlarından doğrulandı. Nakit sınırı da yanıtlandı: fiziksel nakitle 10.000 avro ve üzeri ödeme yasak (Q-15). Kodlamayı bloklayan soru kalmadı.
