<script>
    if (jQuery) {
        jQuery(document).ready(function () {
            var fields = ['billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name'],
                elements = {};

            for (var i = 0; i < fields.length; i++) {
                var el = document.getElementById(fields[i]);
                if (el) {
                    elements[fields[i]] = jQuery(el);
                    elements[fields[i]].on('blur', trackData);
                }
            }

            function trackData () {
                jQuery(document.body).trigger('update_checkout');
            }
        });
    }
</script>
