const { registerPlugin } = wp.plugins;
const { ExperimentalOrderMeta } = wc.blocksCheckout;
const { getSetting } = wc.wcSettings;

const TabbyCartPromotion = ( { cart, extensions, context } ) => {
    var TabbyPromoConfig = getSetting('tabby-cart-promotion_data');
    if (context == 'woocommerce/cart') {
        React.useEffect(() => {
            TabbyPromoConfig.price = (
                Number(cart.cartTotals.total_price) / Math.pow(10, cart.cartTotals.currency_minor_unit)
            ).toFixed(cart.cartTotals.currency_minor_unit);
            new TabbyPromo(TabbyPromoConfig);
        });
        return window.wp.element.createElement("div", {
            className: "tabbyPromo",
            id: "tabbyPromo"
        });
    }
    return null;
};

const render = () => {
    return window.wp.element.createElement(ExperimentalOrderMeta, null, [window.wp.element.createElement(TabbyCartPromotion)]);
};

registerPlugin( 'tabby-checkout', {
    render,
    scope: 'woocommerce-checkout',
} );
