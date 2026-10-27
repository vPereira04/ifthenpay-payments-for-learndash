=== ifthenpay | Payments for LearnDash ===
Contributors: ifthenpay
Tags: LearnDash, payments, multibanco, mb way, pay by link
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell LearnDash courses and groups with ifthenpay: Multibanco, MB WAY, cards, Apple Pay, Google Pay and more, through a secure pay-by-link page.

== Description ==

This plugin adds ifthenpay as a payment gateway in LearnDash LMS. When a student buys a course or a group, they are sent to ifthenpay's secure hosted payment page and pay with the method they prefer, so no card or banking data ever touches your website. ifthenpay then notifies your site server-to-server: the student is enrolled and a regular LearnDash order is created, with LearnDash's own invoice and purchase emails.

LearnDash only keeps paid orders, so every payment attempt (pending, paid, failed, cancelled and expired) is listed in a Payments screen inside LearnDash, built with LearnDash's own design.

In plain terms you get:

* One-time payments for LearnDash courses and groups, from LearnDash's own buttons and registration page
* Automatic enrolment and a LearnDash order as soon as ifthenpay confirms the payment, even for a Multibanco reference paid days later
* A full list of every payment attempt, with filters, export and payment emails
* Secure automatic payment confirmations (no card numbers stored)

All settings are made in LearnDash and in your ifthenpay Backoffice. The plugin is built so site owners can manage payments without needing deep technical knowledge.

== Key Features ==

1. Full integration with LearnDash's payment gateways, buttons, registration page and orders
2. Secure transactions
3. Automatic payment confirmation, enrolment and LearnDash order
4. Support for multiple payment methods (Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay, Cofidis, Pix — whatever's enabled on your Gateway Key)
5. Payment methods catalog synced live from your ifthenpay account, with in-admin "Request activation" for methods not yet enabled
6. LearnDash coupons supported, including on payments confirmed after the coupon would normally expire
7. Payment status on the course page, updated automatically until the payment is confirmed
8. Payments screen with every attempt, filters, CSV/JSON export and payment emails
9. Multi-language payment page (EN, ES, FR, PT)
10. Security first (no card data stored)

== Requirements ==

* An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
* A Gateway Key (request this from ifthenpay support/helpdesk).
* The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
* WordPress 6.7+ and PHP 7.4+, and LearnDash LMS installed and activated.
* LearnDash's currency set to Euro (EUR).
* HTTPS (SSL) enabled on your site.

== Installation ==

1. **Install:** Upload the plugin zip via `Plugins → Add New → Upload`, or install from WordPress.org and Activate.
2. **Credentials:** Ensure your ifthenpay account has an active Gateway Key with the desired payment methods enabled.
3. **Currency:** Go to `LearnDash LMS → Settings → Payments` and set the currency to Euro (EUR).
4. **Connect:** On the same Payments tab, click **Manage** next to ifthenpay, then **Connect ifthenpay** and paste your Backoffice Key.
5. **Configure:** Choose your Gateway Key, switch on the payment methods students can use, click the star next to the default method, and set the payment page description and how many days a payment link stays valid. Switch **Active** on and click **Save**.
6. **Sell:** Any course or group with a "Buy now" price now offers ifthenpay. Payments appear under `LearnDash LMS → Payments`, and paid ones also under `LearnDash LMS → Orders`.

== Frequently Asked Questions ==

= Does this plugin require LearnDash? =

Yes. LearnDash LMS must be installed and active to use this plugin.

= Does it support recurring payments? =

No. This version supports only one-time ("Buy now") prices via pay-by-link. ifthenpay isn't offered on courses or groups with a recurring price.

= Are payment details stored? =

No. The plugin does not store card numbers or full bank details. Only minimal references required for payment matching are kept.

= Which payment methods are supported? =

Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay, Cofidis, Pix).

= Does it support LearnDash coupons? =

Yes. The student pays the price after the coupon, and the coupon is recorded on the LearnDash order, even when the payment is confirmed days later.

= How does the payment process work? =

The student clicks ifthenpay on the course or registration page and is sent to ifthenpay's secure payment page. Once the payment is confirmed, ifthenpay notifies your site, the student is enrolled, and a LearnDash order is created. A student who comes back before the payment is confirmed sees its status on the course page, which updates on its own.

= What happens if a payment fails? =

The attempt is marked as Failed in Payments, and the student sees a "Try again" button on the course page.

= Why don't I see pending payments under LearnDash's Orders? =

LearnDash only creates orders for paid purchases. Pending, failed, cancelled and expired attempts are listed under `LearnDash LMS → Payments`.

= Is there a sandbox? =

ifthenpay may provide test entities; if unavailable, use a low-value live test.

= How secure is the integration? =

Requests are encrypted over HTTPS, every payment notification is checked against your Gateway Key, and no sensitive payment data is stored.

= Does the plugin accept webhooks (callbacks)? =

Yes. The callback URL is registered with ifthenpay automatically when you connect and each time you save.

== External Services ==

This plugin integrates with the ifthenpay payment platform to process payments for LearnDash courses and groups. ifthenpay is a third-party service that provides secure payment processing for local payment methods such as Multibanco, MB WAY, Payshop, and Pix, plus Credit Card and more, depending on what's enabled on your account.

**LearnDash LMS**

* What it is and what it is used for: A learning management system plugin used to sell courses and groups. This plugin adds ifthenpay to its payment gateways.

**ifthenpay Backoffice & Integrations**

* What it is and what it is used for: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
* What data is sent and when:
    * During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval, plus a request for the public payment methods catalog.
    * During payment processing: payment reference, amount, payment page description, enabled payment method accounts, success/error/cancel return URLs, language, the last valid day of the link, the default payment method position, and the student's email address.
    * During webhook registration: the Gateway Key and this site's callback URL, so ifthenpay can notify the site directly when a payment resolves.
    * During payment method activation requests: when an admin requests activation of a new payment method from LearnDash → Settings → Payments → ifthenpay, an email is sent to ifthenpay support (suporte@ifthenpay.com) containing the Backoffice Key, Gateway Key, the requested payment method, the admin's email address, site URL, site name, WordPress version, LearnDash version, and plugin version.
    * During callbacks: payment reference, anti-phishing key, amount, payment method, request ID, and status.
* End-User License Agreement (EULA): [https://ifthenpay.com/eula/](https://ifthenpay.com/eula/)
* Privacy Policy: [https://ifthenpay.com/politica-de-privacidade/](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

== Screenshots ==

1. (Admin Only) ifthenpay in LearnDash's Payments settings
2. (Admin Only) ifthenpay settings: Gateway Key, payment methods and default method
3. (Customers Experience) ifthenpay on the course registration page
4. (Customers Experience) ifthenpay Payment Gateway Secure Payment Page
5. (Customers Experience) Payment status on the course page
6. (Admin Only) LearnDash order with the ifthenpay payment details
7. (Admin Only) Payments

== Changelog ==

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

= 1.0.0 =
* Initial public release: ifthenpay Pay by Link gateway for LearnDash courses and groups, webhook-confirmed enrolment and LearnDash orders, LearnDash coupon support, course-page payment status, and the Payments admin screen.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.

== Support ==

For assistance use the WordPress.org support forum: https://wordpress.org/support/plugin/ifthenpay-payments-for-learndash/

Pre-checks:

* Payment method enabled on Gateway Key AND mapped to Integration
* LearnDash currency set to Euro (EUR)
* Running current recommended versions of WordPress, PHP, & LearnDash

Commercial helpdesk available (no direct email required): https://helpdesk.ifthenpay.com/

* ifthenpay support: suporte@ifthenpay.com

== Security ==

Found a security issue? Please report it privately to suporte@ifthenpay.com with "Security" in the subject, rather than in the public support forum. Include the plugin version, the steps to reproduce, and what an attacker could do. We'll confirm we got your report and keep you posted until a fix ships. See SECURITY.md in the plugin folder for the full policy.
