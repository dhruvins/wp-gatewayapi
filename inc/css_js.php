<?php

wp_register_style('select2', _gwapi_url(). 'lib/select2/css/select2.min.css');
wp_register_script('select2', _gwapi_url(). 'lib/select2/js/select2.min.js', ['jquery'], 1, true);
wp_register_script('gwapi-widgets', _gwapi_url(). 'js/widgets.js', ['jquery', 'select2', 'underscore'], 2, true);


add_action('admin_enqueue_scripts', function () {
    $screen = get_current_screen();

    if ($screen->post_type && !in_array($screen->post_type, ['gwapi-sms', 'gwapi-recipient', 'gwapi-form'])) return;
    if ($screen->taxonomy && !in_array($screen->taxonomy, ['gwapi-recipient-groups'])) return;

    wp_enqueue_style('gwapi-admin', _gwapi_url(). 'css/wpadmin.css');
    wp_enqueue_script('gwapi-admin', _gwapi_url(). 'js/wpadmin.js', ['jquery-ui-tooltip'], 2, true);
    wp_enqueue_script('gwapi-widgets');
    wp_enqueue_style('select2');

    ?>
    <script type="text/javascript">
        var GWAPI_PLUGINDIR = <?= json_encode(_gwapi_url()); ?>;
        var GWAPI_I18N_DEFAULT_ERROR = <?= json_encode(__('Sorry, but there are errors in your input. Please attend to the highlighted fields below.', 'gwapi'));?>;
    </script>
    <?php
}, 100000);