=== Tabby Checkout ===
Contributors: tabbyai
Tags: tabby, tabby plugin, tabby checkout, bnpl, tabby bnpl
Requires at least: 5.7
Tested up to: 7.1
Stable tag: 5.16.1
Requires PHP: 7.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Boost your business with Tabby

== Description ==

Why Tabby:

* Tabby refers hundreds of thousands of shoppers to our partners via co-marketing campaigns.
* We are wherever your customers are. Sell on your website, in-store or in your app.
* Shoppers love the Tabby shopping experience with an industry leading net-promoter-score of 81.
* Interest-free instalments incentivise shoppers to choose Tabby over cash-on-delivery.
* Tabby is permitted by the Saudi Central Bank. Tabby is also Shari’ah-compliant, and PCI DSS certified.
* Tabby has raised +$220M in funding from +10 leading regional and global investors.

Who uses Tabby
Global brands and small businesses use Tabby to accelerate growth and gain loyal
customers by offering easy and flexible payments online and in stores. Tabby
currently operates in Saudi Arabia and UAE.

== External services ==

This plugin connects to the following services:

* Tabby API (api.tabby.ai, api.tabby.sa) - creates and manages Tabby payments for orders paid with Tabby. Data sent: order amount, currency, items, buyer contact details needed for the payment. Sent when a buyer checks out with Tabby and when the order is captured, cancelled or refunded.
* Tabby product feed (plugins-api.tabby.ai, plugins-api.tabby.sa) - shares the product catalogue with Tabby Marketplace when "List products on Tabby Marketplace" is enabled.
* Datadog (logs.browser-intake-datadoghq.eu) - technical logs of the plugin's Tabby API calls and, when "Share store insights" is enabled, anonymised order statistics (products, categories, order totals and payment method). No customer names, emails, phones or addresses are sent; secret keys are masked.

Tabby terms: https://tabby.ai/en-AE/terms-and-conditions - Tabby privacy policy: https://tabby.ai/en-AE/privacy-policy - Datadog privacy policy: https://www.datadoghq.com/legal/privacy/

== Changelog ==

= 5.16.1 =

* Store insights: status changes are sent only when a placed order is cancelled, fails or is paid again after that; abandoned checkouts, and orders and refunds placed before the update, are no longer reported
* Store insights: Apple Pay, Taly, Deema and a few more payment methods recognised; basket amounts include tax
* Product feed: a failing feed request is logged once a day, without the request body
* A webhook for an unknown order (another site on the same Tabby keys, deleted unpaid order) is logged once per payment instead of on every retry
* No Tabby availability request for an empty cart
* Tabby availability check: the cache follows the buyer's phone (or email when there is no phone); rejections are cached for 15 minutes, and a buyer rejected for a basket is not re-checked for the same or a bigger one within that time
* Product feed registration is retried once a day after a refusal (4 hours after a network error)
* Malformed webhook requests no longer cause a PHP error on PHP 8; no PHP warning on block checkout orders

= 5.16.0 =

* Store insights simplified: order placed, status change and refund events with the basket inside; no daily summaries or history upload
* The order confirmation page also accepts the Tabby payment id from the return link, for themes with custom thank-you pages
* An empty Tabby countries selection keeps Tabby off at checkout, as before 5.14.0
* Log fixes: Arabic text in error responses no longer breaks log delivery, customer first and last names masked

= 5.15.0 =

* Store insights setting (order statistics for Tabby Marketplace), enabled by default and can be switched off in Tabby API settings
* Lighter logging: one log request per page load instead of one per entry, fewer repeated webhook and product feed log entries, customer details masked in logs
* Tabby payments are confirmed on the order confirmation page of block themes too (previously only via webhook or the timeout job)
* The order confirmation page checks the Tabby payment only for the buyer's own order (order key in the return link)
* Webhook registration no longer retries countries the API key is not enabled for more than once a day
* MIT license
* Requires PHP 7.4 or newer (the minimum of current WooCommerce)

= 5.14.0 =

* Unsupported countries/currencies removed

= 5.13.3 =

* Improvements for block based checkout

= 5.13.2 =

* Wordpress 7.1 compatibility testing

= 5.13.1 =

* Small changes to checkout logic.

= 5.12.0 =

* Tabby logo updated, small css changes.

= 5.11.0 =

* KSA domain only for SAR currency.

= 5.10.3 =

* Separate domain for KSA payments.

= 5.9.2 =

* Internal improvements and security hardening.

= 5.9.1 =

* Internal improvements and security hardening.

= 5.8.0 =

* Checkout snippets

= 5.7.4 =

* Requires WooCommerce added, minor changes

= 5.6.0 =

* WooCommerce HPOS support added

= 5.5.0 =

* WP REST API support added

= 5.4.0 =

* Prescoring session improvements

= 5.3.0 =

* Disable debug log by default

= 5.2.0 =

* Order status changed to failed for rejected payments

= 5.1.0 =

* Wordpress 6.7 compatibility

= 5.0.15 =

* Fix server load issue

= 5.0.10 =

* Minor fixes 

= 5.0.0 =

* New Product Catalogue Feature

= 4.10.6 =

* Change Tabby promotion merchant code logic 

= 4.10.4 =

* Fix merchant code country selection order 

= 4.10.2 =

* Cache added for prescoring and order history 

= 4.10.1 =

* Compatibility with WordPress 6.5 tested 

= 4.10.0 =

* Compatibility with woocommerce blocks on checkout 

= 4.9.5 =

* Small fixes and changes 

= 4.9.0 =

* Disable Tabby promotions and payment options for specific SKUs

= 4.8.3 =

* Minor fixes, readme update

= 4.8.0 =

* Tested with WP 6.4

= 4.7.0 =

* Plugin mode promo/payment

= 4.6.0 =

* Change logo styling, methods, features

= 4.5.12 =

* Filter 'tabby_checkout_ignore_email' added to ignore email on checkout form

= 4.5.5 =

* Public/secret key validation, bug fixes

= 4.5.0 =

* Create tabby session from back end

= 4.3.2 =

* Add payment availability for 'undefined' country

= 4.3.1 =

* readme.txt file changes

= 4.3.0 =

* Qatar support added

= 4.2.0 =

* Checkout script load optimization

= 4.1.5 =

* Tabby Promo additional configuration option

= 4.1.4 =

* WP SMS Pro support added

= 4.1.3 =

* Minor issues fixed
* New WP version tested

= 4.1.0 =

* Add EXPIRED/REJECTED payment notifications
* Minor fixes

= 3.5.0 =

* Fix issue with early created orders. webhook log detailed
* Remove extended merchant code setting, change datadog log uri

= 3.4.5 =

* Fixes for Order Lock, category field for variation products
* Minor theme compatibility updates and messaging on the checkout
* Optimised Logs and Security Keys updates

= 3.2.4 =

* Return Tabby redirect url regardless request type ajax or not
* Fix warning on checkout
* Fix promotion initial price for variation products
* Fix info button alignment  issue and cc installments default title
* Added buyer history for registered users
* Remove Pay Later option
* Data-tabby-language added for (i) icon on checkout

= 3.0.3 =

* Make installments (non cc) promotions active by default

= 3.0.2 =

* Added Price and currency attributes for (i) mark in checkout method title

= 3.0.1 =

* Added category field for product on checkout session creation

= 3.0.0 =

* Bug fix with wrong function call for webhook registration

== First time installation ==

* Go to WooCommerce -> Settings -> Tabby API and enter the Public API Key and Secret API Key for testing
* Save plugin changes

== Plugin update ==
Be sure to resave your settings after updating the plugin

* Go to WooCommerce -> Settings -> Tabby API 
* Save plugin changes

== Screenshots ==
1. Tabby API settings
2. Tabby API settings

== Who do we share your information with? ==
We may share your personal information:

(a) with any member of our group (which includes our subsidiaries and our ultimate holding company and its subsidiaries, who support our processing of personal data under this Notice, who we support in processing your personal data, or who we otherwise share your personal data with.

(b) with selected third parties, including the credit reference agencies we work with. Our selected third parties may include:

(i) Organisations who process your personal data on our behalf and in accordance with our instructions and the Data Protection Law. This includes in supporting the services we offer through the Platform in particular those providing website and data hosting services, providing fulfilment services, distributing any communications we send, supporting or updating marketing lists, facilitating feedback on our services and providing IT support services from time to time. These organisations (which may include third party suppliers, agents, sub-contractors and/or other companies in our group) will only use your information to the extent necessary to perform their support functions.

(ii) Advertisers and advertising networks that require the data to select and serve relevant adverts to you and others. We do not disclose information about identifiable individuals to our advertisers, but we will provide them with aggregate information about our users. We may make use of the personal data we have collected from you to enable us to comply with our advertisers' wishes by displaying their advertisement to that target audience and subject to the cookie section of this Notice.

(iii) Analytics and search engine providers that assist us in the improvement and optimisation of our site (this will not identify you as an individual).

(iv) Merchants and business partners who provide services to you, and with whom we have entered into agreements in relation to the processing of your personal data a list of whom can be provided upon request.

(v) Credit Reference Agencies for the purpose of assessing your credit score whether when setting up an account with us or on an ongoing basis. We do this to assess creditworthiness and product suitability, check your identity, manage your account, trace and recover debts and prevent criminal activity. We continue to exchange information about you, your settled accounts and debts not fully repaid on time with the Credit Reference Agencies while you use our services. The Credit Reference Agencies will share your information with other organisations. Your data will also be linked to the data of your spouse, any joint applicants or other financial associates.

(vi) Payment processing providers who provide secure payment processing services.

(vii) Debt collection agencies, should your account fall into arrears, in order to collect the amount you owe us from you.

(c) any person to whom disclosure is necessary to enable us to enforce our rights under this Privacy Notice or under any agreement we have with you, or to protect our rights or the rights of third parties. This includes exchanging information with law enforcement agencies (including regulators) or other similar government bodies

(d) where required to do so by court order or where we are under a duty to disclose or share your information in order to comply with (and/or where we believe we are under a duty to comply with) any legal obligation.

(e) in the event that we sell or buy any business or assets, in which case we will disclose your personal data to the prospective seller or buyer.

If we share your personal information with our group companies or other third parties, we will take steps to protect your personal information in our contractual agreements with these third parties, and to require that they have appropriate technical and organisational security measures in place, in compliance with applicable data protection laws.


== Plugin Update: New Product Catalogue Feature ==
We’ve introduced an exciting new feature to the Tabby WooCommerce plugin – the **Product Catalogue Feature**, designed to help you list your store’s products seamlessly on the Tabby Shop. Here are the advantages this feature brings to your business:

**Advantages:**
* **Automated Product Listing:** Easily list your entire WooCommerce store inventory on the Tabby Shop without manual intervention.
* **Real-Time Stock Updates:** Keep your product listings up-to-date effortlessly, as the plugin automatically syncs inventory availability and reflects out-of-stock items in real time.
* **Increased Visibility:** Showcase your products to a broader audience by making them visible to all Tabby app users.
* **Traffic Generation:** Drive interested shoppers directly to your WooCommerce store, increasing clicks and potential sales.
* **Simplified Setup:** The feature is enabled by default, so there’s nothing you need to do. If needed, you can manage it in the Tabby API settings by simply enabling or disabling the appropriate checkbox.

This feature helps you reach more shoppers and boost your store’s visibility through the Tabby platform.

== Tabby Promo ==
Customers are not always aware of the different financing options available to them before they reach the checkout. Tabby promo helps your customers learn about the advantages and possibilities of alternative payment methods which has a great impact on the conversion rate and customers' awareness.

Tabby promotions script for product info and shopping cart pages:
https://checkout.tabby.ai/tabby-promo.js

Tabby billing plan for checkout payment methods description:
https://checkout.tabby.ai/cms-plugins.js


== Privacy policy ==
* https://tabby.ai/en-AE/privacy-policy

== Terms and conditions ==
* https://tabby.ai/en-AE/toc

== F. A. Q. ==

For more information about the documentation, user guides and the FAQs, please visit: our website (https://merchantsupport.tabby.ai/hc/en-us)
