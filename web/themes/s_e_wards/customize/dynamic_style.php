<style class="customize">
<?php
    $customize = (array)json_decode((string)$json, true);
    if($customize):
?>

    <?php //================= Font Body Typography ====================== ?>
    <?php if(isset($customize['font_family_primary'])  && $customize['font_family_primary'] != '---'){ ?>
        body,.block.block-blocktabs .ui-widget,.block.block-blocktabs .ui-tabs-nav > li > a
        {
            font-family: <?php echo $customize['font_family_primary'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['font_family_second'])  && $customize['font_family_second'] != '---'){ ?>
        .pager ul.pager__items > li a,header .header-button a,.sew-user-region .user-content,.page-user-login form .form-item label, .page-user-register form .form-item label, .page-user-pass form .form-item label,
        .path-user .user-information .field--name-field-user-fullname,.post-block .post-title a,.post-block .post-meta,.post-block-2 .post-content .post-categories,.team-block.team-v1 .team-content .team-name,.team-block.team-v2 .team-content .team-name,
        .post-slider.post-block .post-categories a,.nav-tabs > li > a,.btn, .btn-white, .btn-theme, .btn-theme-small, .btn-theme-second, .more-link a, .btn-theme-submit, input.form-submit,.btn-inline,.progress-label,.progress .percentage,.pricing-table .plan-name .title,
        .pricing-table .content-wrap .plan-price .price-value .value,.pricing-table .content-wrap .plan-price .interval,#node-single-comment #comment-form .form-item label,.webform-submission-form .form-item label,.webform-submission-form .form-actions .webform-button--submit,
        form.node-form .form-item label,form.node-form .form-item input[type='text']:not(.chosen-search-input), form.node-form .form-item input[type='search'], form.node-form .form-item input[type='password'], form.node-form .form-item input[type='email'], form.node-form .form-item textarea, form.node-form .form-item select,
        form.node-form .form-item button,form.node-form fieldset legend,form.node-form details summary,form.node-form .tabledrag-toggle-weight-wrapper button,form.node-form table thead,.block .block-title,.most-search-block ul > li,.contact-link .title,.company-presentation .title,.chosen-container-single,
        .navigation .sew_menu > li > a,.navigation .sew_menu .megamenu > .sub-menu > li > ul.sub-menu li,.navigation .sew_menu .sub-menu > li > a,.tags-list .item-list > ul > li a,.gsc-icon-box .highlight_content .title,.gsc-icon-box-color .content-inner .box-title,.milestone-block.position-icon-top .milestone-text,.milestone-block.position-icon-top-2 .milestone-text,
        .milestone-block.position-icon-left .milestone-right .milestone-text,.gsc-content-images-parallax.style-v1 .content .title,.gsc-content-images-parallax.style-v2 .content .title,.gsc-video-box.style-1 .video-content .left,.gsc-video-box.style-2 .video-content .link-video strong,.gsc-video-box.style-2 .video-content .button-review a,.gsc-team .team-name,.gsc-team.team-vertical .team-name,
        .gsc-team.team-circle .team-name,.gsc-quotes-rotator .cbp-qtrotator .cbp-qtcontent .content-title,.sew-job-box .content-inner .job-type,.sew-job-box .content-inner .box-title .title,.gsc-our-gallery .item .box-content .title,.gsc-text-rotate .rotate-text .primary-text,.gsc-heading .sub-title,.gsc-chart .content .title,.gsc-tabs .tabs_wrapper.tabs_horizontal .nav-tabs > li a,.gsc-tabs .tabs_wrapper.tabs_vertical .nav-tabs > li a,
        .sew-offcanvas-mobile .sew-navigation .sew_menu > li > a,#listing-main-map .leaflet-popup-content-wrapper .leaflet-popup-content .sew-map-content-popup .content-inner .title,.sew-listings-map-page .map-action,
        .sew-listings-map-page .map-action-mobile,.sew-listings-full-page-2 .map-action,.views-exposed-form .form-item input[type='text']:not(.chosen-search-input), .views-exposed-form .form-item input[type='search'], .views-exposed-form .form-item input[type='password'], .views-exposed-form .form-item input[type='email'], .views-exposed-form .form-item textarea, .views-exposed-form .form-item select,
        .views-exposed-form .form-item label,.views-exposed-form fieldset .fieldset-legend,.views-exposed-form .form-actions input.form-submit,.node-listing-single .listing-top .listing-top-content .listing-price .label,.node-listing-single .listing-nav .listing-nav-inner ul > li a,.node-listing-single .listing-content-main .listing-info-block .title,.node-listing-single .listing-content-main .listing-info-block.listing-location .listing-location-taxonomy a,
        .node-listing-single .listing-content-main .listing-info-block.business-info ul.business-info li,.node-listing-single .listing-content-main .block .block-title,.event-block .event-content .event-info,.event-block .event-content .event-meta,.event-block-2 .event-date,.portfolio-v2 .content-inner .title a,.portfolio-v2 .content-inner .category,.testimonial-node-2 .quote,.testimonial-node-2 .title,.testimonial-node-3 .quote,.testimonial-node-3 .title,#customize-sewards-preivew .card .card-header a,
        #customize-sewards-preivew .form-group label,
        h1, h2, h3, h4, h5, h6,.h1, .h2, .h3, .h4, .h5, .h6
        {
            font-family: <?php echo $customize['font_family_second'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['font_body_size'])  && $customize['font_body_size']){ ?>
        body{
            font-size: <?php echo ($customize['font_body_size'] . 'px'); ?>;
        }
    <?php } ?>    

    <?php if(isset($customize['font_body_weight'])  && $customize['font_body_weight']){ ?>
        body{
            font-weight: <?php echo $customize['font_body_weight'] ?>;
        }
    <?php } ?>    

    <?php //================= Body ================== ?>

    <?php if(isset($customize['body_bg_image'])  && $customize['body_bg_image']){ ?>
        body{
            background-image:url('<?php echo \Drupal::service('extension.list.theme')->getPath('s_e_wards') .'/images/patterns/'. $customize['body_bg_image']; ?>');
        }
    <?php } ?> 
    <?php if(isset($customize['body_bg_color'])  && $customize['body_bg_color']){ ?>
        body{
            background-color: <?php echo $customize['body_bg_color'] ?>!important;
        }
    <?php } ?> 
    <?php if(isset($customize['body_bg_position'])  && $customize['body_bg_position']){ ?>
        body{
            background-position:<?php echo $customize['body_bg_position'] ?>;
        }
    <?php } ?> 
    <?php if(isset($customize['body_bg_repeat'])  && $customize['body_bg_repeat']){ ?>
        body{
            background-repeat: <?php echo $customize['body_bg_repeat'] ?>;
        }
    <?php } ?> 

    <?php //================= Body page ===================== ?>
    <?php if(isset($customize['text_color'])  && $customize['text_color']){ ?>
        body .body-page{
            color: <?php echo $customize['text_color'] ?>;
        }
    <?php } ?>

    <?php if(isset($customize['link_color'])  && $customize['link_color']){ ?>
        body .body-page a{
            color: <?php echo $customize['link_color'] ?>!important;
        }
    <?php } ?>

    <?php if(isset($customize['link_hover_color'])  && $customize['link_hover_color']){ ?>
        body .body-page a:hover{
            color: <?php echo $customize['link_hover_color'] ?>!important;
        }
    <?php } ?>

    <?php //===================Header=================== ?>
    <?php if(isset($customize['header_bg'])  && $customize['header_bg']){ ?>
        header#header, header.header-default .header-main{
            background: <?php echo $customize['header_bg'] ?>!important;
        }
    <?php } ?>
    <?php if(isset($customize['header_color'])  && $customize['header_color']){ ?>
        header#header, header#header .header-main{
            color: <?php echo $customize['header_color'] ?>!important;
        }
    <?php } ?>
    <?php if(isset($customize['header_color_link'])  && $customize['header_color_link']){ ?>
        header#header .header-main a{
            color: <?php echo $customize['header_color_link'] ?>!important;
        }
    <?php } ?>

    <?php if(isset($customize['header_color_link_hover'])  && $customize['header_color_link_hover']){ ?>
        header#header .header-main a:hover{
            color: <?php echo $customize['header_color_link_hover'] ?>!important;
        }
    <?php } ?>

   <?php //===================Menu=================== ?>
    <?php if(isset($customize['menu_bg']) && $customize['menu_bg']){ ?>
        .main-menu, ul.sew_menu, .header.header-default .stuck{
            background: <?php echo $customize['menu_bg'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['menu_color_link']) && $customize['menu_color_link']){ ?>
        .main-menu ul.sew_menu > li > a, .main-menu ul.sew_menu > li > a:after, .main-menu ul.sew_menu > li > a:before, .main-menu ul.sew_menu > li > a .icaret{
            color: <?php echo $customize['menu_color_link'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['menu_color_link_hover']) && $customize['menu_color_link_hover']){ ?>
        .main-menu ul.sew_menu > li > a:hover, .main-menu ul.sew_menu > li > a:after, .main-menu ul.sew_menu > li > a:before, .main-menu ul.sew_menu > li > a .icaret{
            color: <?php echo $customize['menu_color_link_hover'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['submenu_background']) && $customize['submenu_background']){ ?>
        .main-menu .sub-menu{
            background: <?php echo $customize['submenu_background'] ?>!important;
            color: <?php echo $customize['submenu_color'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['submenu_color']) && $customize['submenu_color']){ ?>
        .main-menu .sub-menu{
            color: <?php echo $customize['submenu_color'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['submenu_color_link']) && $customize['submenu_color_link']){ ?>
        .main-menu .sub-menu a, .main-menu .sub-menu a:after, .main-menu .sub-menu a:before, .main-menu .sub-menu a .icaret {
            color: <?php echo $customize['submenu_color_link'] ?>!important;
        }
    <?php } ?> 

    <?php if(isset($customize['submenu_color_link_hover']) && $customize['submenu_color_link_hover']){ ?>
        .main-menu .sub-menu a:hover, .main-menu .sub-menu a:after, .main-menu .sub-menu a:before, .main-menu .sub-menu a .icaret{
            color: <?php echo $customize['submenu_color_link_hover'] ?>!important;
        }
    <?php } ?> 

    <?php //===================Footer=================== ?>
    <?php if(isset($customize['footer_bg']) && $customize['footer_bg'] ){ ?>
        #footer .footer-center{
            background: <?php echo $customize['footer_bg'] ?>!important;
        }
    <?php } ?>

     <?php if(isset($customize['footer_color'])  && $customize['footer_color']){ ?>
        #footer .footer-center, #footer .block .block-title span, body.footer-white #footer .block .block-title span{
            color: <?php echo $customize['footer_color'] ?> !important;
        }
    <?php } ?>

    <?php if(isset($customize['footer_color_link'])  && $customize['footer_color_link']){ ?>
        #footer .footer-center ul.menu > li a::after, .footer a{
            color: <?php echo $customize['footer_color_link'] ?>!important;
        }
    <?php } ?>    

    <?php if(isset($customize['footer_color_link_hover'])  && $customize['footer_color_link_hover']){ ?>
        #footer .footer-center a:hover{
            color: <?php echo $customize['footer_color_link_hover'] ?> !important;
        }
    <?php } ?>    

    <?php //===================Copyright======================= ?>
    <?php if(isset($customize['copyright_bg'])  && $customize['copyright_bg']){ ?>
        .copyright{
            background: <?php echo $customize['copyright_bg'] ?> !important;
        }
    <?php } ?>

     <?php if(isset($customize['copyright_color'])  && $customize['copyright_color']){ ?>
        .copyright{
            color: <?php echo $customize['copyright_color'] ?> !important;
        }
    <?php } ?>

    <?php if(isset($customize['copyright_color_link'])  && $customize['copyright_color_link']){ ?>
        .copyright a{
            color: $customize['copyright_color_link'] ?>!important;
        }
    <?php } ?>    

    <?php if(isset($customize['copyright_color_link_hover'])  && $customize['copyright_color_link_hover']){ ?>
        .copyright a:hover{
            color: <?php echo $customize['copyright_color_link_hover'] ?> !important;
        }
    <?php } ?>    
<?php endif; ?>    
</style>
