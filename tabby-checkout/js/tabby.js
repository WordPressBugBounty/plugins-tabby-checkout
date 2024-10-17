jQuery(document).ready(function () {
    jQuery('form input').on('change', updateTabbyCheckout);
    jQuery(document.body).on('updated_checkout', updateTabbyCheckout);
    
    function updateTabbyCheckout() {
        if (!window.tabbyRenderer) window.tabbyRenderer = new TabbyRenderer();
        tabbyRenderer.update();
    }

    updateTabbyCheckout();
});
class TabbyRenderer {
    constructor () {
        this.payment = null;
        this.email = null;
        this.phone = null;
        this.lastMethod = null;
        this.methods = {
            creditCardInstallments: 'credit_card_installments',
            installments: 'installments',
            payLater: 'pay_later'
        };
        this.products = [];
        this.product = null;
        this.formFilled = false;
        this.formSubmitted = false;
        this.actualSession = 0;
        // update payment modules on phone/email change
        jQuery( 'form' ).on( 
            'change', 
            '#billing_email, #billing_phone', 
            function () {jQuery( document.body ).trigger('update_checkout')}
        );
        // pay_for_order page
        if (this.isPayForOrderPage() && jQuery('#order_review').length) {
            jQuery('#order_review').submit(function (e) {
                tabbyRenderer.updatePaymentIdField();
            });
        }
        jQuery( document.body ).bind( 'payment_method_selected', this.updatePlaceOrderButton );
        for (var i in this.methods) {
            jQuery( 'form' ).bind( 'checkout_place_order_tabby_' + this.methods[i], this.updatePaymentIdField.bind(this));
        }
        this.style = document.createElement('style');
        this.style.type = 'text/css';
        this.adjustStyleSheet();
        setTimeout(function () {
            tabbyRenderer.updatePlaceOrderButton();
        }, 300);
        document.getElementsByTagName('head')[0].appendChild(this.style);
    }
    getFieldEmail() {
        if (typeof tabbyConfig !== 'undefined' && tabbyConfig.ignoreEmail) {
            return {val: function() {return ' ';}};
        }
        return this.getFieldValue('email');
    }
    getFieldPhone() {
        return this.getFieldValue('phone');
    }
    getFieldFirstName() {
        return this.getFieldValue('first_name');
    }
    getFieldLastName() {
        return this.getFieldValue('last_name');
    }
    getFieldValue(name) {
        // wp sms support
        if (name == 'phone' && jQuery('#wp-sms-input-mobile').length) {
            return jQuery('#wp-sms-input-mobile');
        }
        // primary data from billing
        var field = jQuery('#billing_' + name);
        // support for shipping fields if no billing present
        if (!field.length || !field.val()) {
            if (jQuery('#shipping_' + name).length && jQuery('#shipping_' + name).val()) {
                field = jQuery('#shipping_' + name);
            }
        } 
        // support checkout fields without prefix
        if (!field.length || !field.val()) {
            if (jQuery('#' + name).length && jQuery('#' + name).val()) {
                field = jQuery('#' + name);
            }
        } 
        
        return field;
    }
    getLocale() {
        if (this.config.language != 'auto') return this.config.language;
        return this.config.localeSource && this.config.localeSource == 'html' ? document.documentElement.lang : this.config.locale;
    }
    updatePlaceOrderButton() {
        if (typeof tabbyRenderer == 'undefined') return;
        var selected = jQuery('input[name="payment_method"]:checked').val();
        
        jQuery("#place_order").attr('disabled', false);
        for (var i in tabbyRenderer.methods) {
            if (selected == ('tabby_' + tabbyRenderer.methods[i])) tabbyRenderer.product = i;
            // remove error
            jQuery(".payment_box.payment_method_tabby_" + tabbyRenderer.methods[i] + ' > .woocommerce-error').remove();
            jQuery('.payment_box.payment_method_tabby_' + tabbyRenderer.methods[i] + ' > #' + tabbyRenderer.methods[i] + 'Card, .payment_box.payment_method_tabby_' + tabbyRenderer.methods[i] + ' > .tabbyDesc').css('display', 'block');
            if ((selected == 'tabby_' + tabbyRenderer.methods[i]) && !tabbyRenderer.products.hasOwnProperty(i)) {
                jQuery("#place_order").attr('disabled', 'disabled');
                if (tabbyConfig && tabbyRenderer.formFilled) {
                    jQuery('.payment_box.payment_method_tabby_' + tabbyRenderer.methods[i] + ' > #' + tabbyRenderer.methods[i] + 'Card, .payment_box.payment_method_tabby_' + tabbyRenderer.methods[i] + ' > .tabbyDesc').css('display', 'none');
                    jQuery(".payment_box.payment_method_tabby_" + tabbyRenderer.methods[i]).append(
                        jQuery("<div class='woocommerce-error'>").html(tabbyConfig.notAvailableMessage)
                    );
                }
            }
        }
    }
    update() {
        // check payment methods form
        jQuery("input[name=\"payment_method\"]").each (function () {
            if (/tabby_/.test(jQuery(this).val())) {
                // check if (i) added to label
                if (!jQuery(this).parent().find("label").find("[data-tabby-info]").length) {
                    jQuery(this).parent().find('label').prepend(jQuery(this).parent().find(".payment_box").find("img[data-tabby-info]"));
                    jQuery(this).parent().find("label").find("[data-tabby-info]").css('display', 'inline-block');
                }

            }
        });
        this.config = window.tabbyConfig;
        this.adjustStyleSheet();
        if (!this.canUpdate()) return;
        var payment = this.buildPayment();
        if (tabbyRenderer.config.debug) console.log(payment);
        if ((JSON.stringify(payment) == this.paymentJSON) && (this.oldMerchantCode == this.config.merchantCode)) {
            // set form field values (because payment methods can be updated)
            this.setPaymentIdForm();
            return;
        }
        this.payment = payment;
        this.paymentJSON = JSON.stringify(payment);
        this.oldMerchantCode = this.config.merchantCode;
        this.create();
        tabbyRenderer.relaunchTabby = false;
    }
    ddLog(msg, data) {
        if (typeof ddLog !== 'undefined') {
            ddLog(msg, data);
        }
    }
    unblockForm() {
        try {
            jQuery('form').removeClass( 'processing' ).unblock();
        } catch (error) {
            if (tabbyRenderer.config.debug) console.log(error);
        }
    }
    setPaymentId(payment_id) {
        this.payment_id = payment_id;
        this.setPaymentIdForm();
    }
    setPaymentIdForm() {
        jQuery("input[name^=tabby_]").filter("[name$=payment_id]").val(this.payment_id);
        // save webUrl for every product
        for (var i in this.methods) {
            if (this.products.hasOwnProperty(i)) {
                jQuery("input[name=tabby_"+this.methods[i]+"_web_url]").val(tabbyRenderer.products[i][0].webUrl);
            } else {
                jQuery("input[name=tabby_"+this.methods[i]+"_web_url]").val('');
            }
        }
    }
    updatePaymentIdField() {
        if (this.payment_id) {
            this.setPaymentIdForm();
            this.formSubmitted = true;
            return true;
        }
        return false;
    }
    create() {
        tabbyRenderer.formFilled = false;
        this.disableButton();
        this.setPaymentId(null);
        // create session configuration
        var tabbyConfig = {
            apiKey: this.config.apiKey
        };
        tabbyConfig.payment = this.payment;
        tabbyConfig.merchantCode = this.config.merchantCode;
        tabbyConfig.lang = this.getLocale();
        tabbyConfig.merchantUrls = this.config.merchantUrls;
        // clean available products
        tabbyRenderer.products = [];
        if (tabbyRenderer.config.debug) console.log(tabbyConfig);
        var sessNum = ++this.actualSession;
        window.TabbyCmsPlugins.createSession(tabbyConfig).then( (sess) => {
            tabbyRenderer.formSubmitted = false;
            tabbyRenderer.formFilled = true;
            // do nothing
            if (tabbyRenderer.actualSession > sessNum) {
                if (tabbyRenderer.config.debug) console.log("ignore old response");
                return;
            }
            // create session error
            if (!sess.hasOwnProperty('status') || sess.status != 'created') {
                if (tabbyRenderer.config.debug) console.log('create session error');

                tabbyRenderer.disableButton();
                return;
            }
            // update currently available products
            tabbyRenderer.products = sess.availableProducts;
            // update payment id field
            tabbyRenderer.setPaymentId(sess.payment.id);
            tabbyRenderer.ddLog('payment created', {payment:{id:sess.payment.id}});

            tabbyRenderer.enableButton();

            if (tabbyRenderer.relaunchTabby) {
                tabbyRenderer.relaunchTabby = false;
                tabbyRenderer.launch();
            }
        });
    }
    launch() {

        if (!tabbyRenderer.formSubmitted) {
            setTimeout(function () {
                tabbyRenderer.unblockForm();
                jQuery("#place_order").trigger('click');
            }, 300);
            return false;
        }
        var product = tabbyRenderer.product;
        if (tabbyRenderer.config.debug) console.log('launch with product', tabbyRenderer.product);

        if (tabbyRenderer.relaunchTabby) {
            tabbyRenderer.create();
        } else {
            // remove form blocking
            tabbyRenderer.unblockForm();
            jQuery( window ).unbind('beforeunload');

            document.location.href = tabbyRenderer.products[product][0].webUrl;
        }

        return false;
    }
    adjustStyleSheet() {
        if (this.config && this.config.hideMethods) {
            this.style.innerHTML = '';
            for (var i in this.methods) {
                if (this.products.hasOwnProperty(i)) {
                    this.style.innerHTML += '.payment_method_tabby_' + this.methods[i] + '{display:block;}\n';
                } else {
                    if (tabbyConfig && tabbyRenderer.formFilled) {
                        this.style.innerHTML += '.payment_method_tabby_' + this.methods[i] + '{display:none;}\n';
                    }
                }
            }
        }
        this.updatePlaceOrderButton();
    }
    enableButton() {
        this.adjustStyleSheet();
    }
    disableButton() {
        this.adjustStyleSheet();
    }
    canUpdate() {
        if (!this.isPayForOrderPage()) {
            if (!this.getFieldFirstName().val()) return false;
            if (!this.getFieldEmail().val() || !this.getFieldPhone().val()) return false;
        };
        if (!window.tabbyConfig) return false;
        // reload order history if needed
        if (!this.loadOrderHistory()) return false;
        return true;
    }
    loadOrderHistory() {
        if (this.getFieldEmail().val() == this.email && this.getFieldPhone().val() == this.phone) {
            return true;
        }
        if ( typeof wc_checkout_params === 'undefined' ) {
            return false;
        }

        var data = {
            email: this.getFieldEmail().val(),
            phone: this.getFieldPhone().val(),
            security: wc_checkout_params.get_order_history_nonce
        };

        tabbyRenderer.xhr = jQuery.ajax({
            type:       'POST',
            url:        wc_checkout_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'get_order_history' ),
            data:       data,
            success:    function( data ) {
                tabbyRenderer.order_history = data.order_history;
                tabbyRenderer.email = data.email;
                tabbyRenderer.phone = data.phone;
                tabbyRenderer.update();
            }
        });

        return false;
    }
    buildPayment() {
        var payment = this.config.payment;
        payment.buyer = this.getBuyerObject();
        if (this.config.buyer_history) {
            payment.buyer_history = this.config.buyer_history;
        }
        payment.shipping_address = this.getShippingAddress();
        payment.order_history = this.order_history;
        return payment;
    }
    getBuyerObject() {
        if (this.isPayForOrderPage()) return this.config.buyer;
        return {
            email: this.getFieldEmail().val().toString(),
            name: this.getFieldFirstName().val() + (this.getFieldLastName().length ? ' ' + this.getFieldLastName().val() : ''),
            phone: this.getFieldPhone().val().toString()
        }
    }
    isPayForOrderPage() {
        return jQuery('input[name=woocommerce_pay]').length && (jQuery('input[name=woocommerce_pay]').val() == 1);
    }
    getShippingAddress() {
        if (this.isPayForOrderPage()) return this.config.shipping_address;
        const prefix = jQuery('#ship-to-different-address-checkbox:checked').length > 0 ? 'shipping' : 'billing';
        return {
            address: this.getAddressStreet(prefix),
            city: this.getAddressCity(prefix)
        }
    }
    getAddressStreet(prefix) {
        const street1 = jQuery('#' + prefix + '_address_1');
        const street2 = jQuery('#' + prefix + '_address_2');
        
        return (street1 ? street1.val() : '') + (street2 && street2.val() ? ', ' + street2.val() : '');
    }
    getAddressCity(prefix) {
        const city = jQuery('#' + prefix + '_city');
        return city ? city.val() : null;
    }
    placeTabbyOrder() {
        // assign payment id to related input
        this.setPaymentIdForm();
        jQuery('#place_order').trigger('click');
    }
}
