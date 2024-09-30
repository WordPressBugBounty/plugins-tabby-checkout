/**
 * External dependencies
 */
const registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
const getPaymentMethodData = window.wc.wcSettings.getPaymentMethodData;
const __ = window.wp.i18n.__;

const settings = getPaymentMethodData( 'tabby_installments', {} );
const defaultLabel = 'Pay in 4. No interest, no fees.';
const title = settings?.title || '';


/**
 * Icon component
 */

const TabbyIcon = () => {
    return React.createElement('img', {
        src: settings?.icon_src,
        style: {marginRight:'10px'},
        alt: title
    }, null);
}

const TabbyInfo = () => {
    return React.createElement('img', settings?.config?.info, null);
}

const Label = () => {
    return [window.wp.element.createElement(TabbyIcon), React.createElement('span', null, title), window.wp.element.createElement(TabbyInfo)];
}

const InitTabbyCard = (className, classParams) => {
    if (eval('typeof ' + className) == 'function') {
        eval('new ' + className + '(' + classParams + ');');
        return true;
    }
    return false;
};

const TabbyPromotionCard = () => {
    React.useEffect(() => {
        // init Tabby Promo Card
        if (!InitTabbyCard(settings?.config?.jsClass, settings?.config?.jsConf)) {
            let timerId = setInterval(function () {
                if (InitTabbyCard(settings?.config?.jsClass, settings?.config?.jsConf)) {
                    clearInterval(timerId);
                }
            }, 300);
        }
    });
    return React.createElement('div', {id: settings?.config?.id}, '');    
}

const TabbyInstallmentsMethodName = 'tabby_installments';
/**
 * Content component
 */
const Content = () => {
    switch (settings?.config?.class) {
        case 'empty':
            return null;
        case 'tabbyDesc':
            return React.createElement('span', null, settings?.config?.html || '');
        default: 
            return window.wp.element.createElement(TabbyPromotionCard);
    }
};

/**
 * Tabby installments payment method config object.
 */

const tabbyInstallmentsPaymentMethod = {
    name: TabbyInstallmentsMethodName,
    label: Object( window.wp.element.createElement ) (Label, null),
    content: Object( window.wp.element.createElement ) (Content, null),
    edit: Object( window.wp.element.createElement ) (Content, null),
    canMakePayment: (props) => {
        return true;
    },
    ariaLabel: title,
    placeOrderButtonLabel: __(
        'Proceed to Tabby',
        'tabby-checkout'
    ),
    supports: {
        features: settings?.supports ?? []
    }
};

registerPaymentMethod( tabbyInstallmentsPaymentMethod );

