<div class="wrap">
    <h1>Akaunting for WooCommerce</h1>
    <form action="options.php" method="post">
        <?php settings_fields('akawoo-options'); ?>
        <?php do_settings_sections('akawoo-options'); ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row">Akaunting URL</th>
                <td><input type="text" name="akawoo_url" required="required" value="<?php echo esc_attr(get_option('akawoo_url')); ?>" /></td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Settings">
            <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=advanced&section=keyspage=wc-settings&tab=advanced&section=keys'); ?>" class="button button-default" >Get WooCommerce API Key</a>
        </p>
    </form>
</div>