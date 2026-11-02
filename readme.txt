=== DEV3 AI Size Guide & Fit Advisor for WooCommerce ===
Contributors: dev3tech
Tags: size chart, size guide, fit finder, woocommerce, returns
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Size charts with a cm/in switch, a "What size am I?" quiz, a phone-friendly bottom sheet, DEV3 AI chart drafting and learning from return reasons.

== Description ==

DEV3 AI Size Guide & Fit Advisor helps shoppers pick the right size the first time and helps you see which styles run small or large.

**Size charts (no AI needed)**

* Grid editor with several tables per chart (body, garment, international sizes), ranges such as 86-91, edit in cm or inches.
* 12 ready-made templates: women's tops, dresses, bottoms, men's tops, jeans, unisex tees, women's and men's shoes, kids, bras, rings, hats.
* Paste a supplier table (tab, comma, semicolon or space separated) and import it without AI; inches are detected and converted.
* Assign by category (sub-categories included), tag, product, exclusions and priority, plus a per-product override.
* How-to-measure guide with an image and a shopper fit note.

**On the storefront**

* "Size guide" and "What size am I?" buttons near add to cart, a product tab, or both.
* A centred dialog on desktop and a real bottom sheet on phones (swipe down, Escape, focus trap, safe areas).
* Tables scroll in both directions with a sticky header row and sticky size column; cm/in switch remembered per device.
* Works with block themes (Twenty Twenty-Five) and classic themes (Twenty Twenty-One).
* Shortcodes `[dev3sg_size_guide]`, `[dev3sg_size_chart]`, `[dev3sg_fit_quiz]` and the **Size Chart** block.

**"What size am I?" quiz**

* Pure measurement maths: chest, waist, hips, foot length and every other measure in your chart, fit preference, the size usually worn in US/UK/EU, or a height-and-weight estimate.
* Recommended size, an alternative when between sizes, confidence, per-measure verdicts and "Select this size" for the variation dropdown.
* DEV3 AI can rewrite the explanation in friendly words (cached, rate-limited, with a written fallback).

**Returns-reason learning**

* Reads WooCommerce refund reasons, a "Fit feedback" box on orders (HPOS and legacy), manual records and the `dev3sg_record_return` action.
* Classifies "too small / too large" in several languages; DEV3 AI can classify unclear notes (you accept each suggestion).
* When a style keeps coming back one way, you get a "runs small / large" suggestion; accepting it moves the quiz one size and can add a shopper note.

**DEV3 AI (Google Gemini, your own key)**

* Draft a full chart from product data, a pasted chart or a photo / screenshot of a supplier chart.
* Write the how-to-measure guide, summarise return notes and suggest category assignments.
* Everything is a draft until you accept it. The plugin works fully without AI or a key.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin and activate it (WooCommerce must be active).
2. Open **DEV3 AI Size Guide** → Templates and create your first chart, then assign it to categories under "Where it shows".
3. Optional: add a Google Gemini API key under API Config to turn on the DEV3 AI features.

== Frequently Asked Questions ==

= Does it work without AI? =
Yes. Charts, templates, paste import, the quiz maths, analytics and return learning all work without a key.

= Which data is sent to Google Gemini? =
Only for the features you use: product title, categories, description and size options (chart drafts), pasted text or the image you upload, the quiz result (no names or emails) and masked return notes.

= Where are shopper measurements stored? =
Only in the shopper's browser when they tick "Remember my measurements". The server keeps anonymous counts only.

= Is it compatible with HPOS and the Cart/Checkout blocks? =
Yes, both are declared and supported.

== Screenshots ==

1. Size guide popup with the cm/in switch.
2. Phone bottom sheet with the fit quiz result.
3. Chart editor with live preview and the quiz simulator.
4. DEV3 AI chart draft from a pasted chart.
5. Dashboard: guide opens, quizzes, add-to-cart rate and coverage.
6. Returns & learning: "runs small" suggestion and DEV3 AI summary.

== Changelog ==

= 1.0.0 =
* Initial release.
