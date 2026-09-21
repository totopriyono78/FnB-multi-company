# Fixture promo bersama (FR-MENU-11..13, BR-18)

`input` = `cart`, `context`, `promotions` (bentuk `Promotion::toEngineArray()`); `expected` = hasil `PromotionEngine::apply()`.
Dipakai PHP (`backend/tests/Unit/Pricing/PromotionFixturesTest.php`) dan nanti Dart (POS offline).
Nilai diharapkan dihitung manual.
