<?php
defined('SSLIVE') || exit;

if (is_post() || !Auth::user()) {
    Auth::logout();
    start_session();
    flash('success', __('auth.logged_out'));
}
redirect(url('login'));
