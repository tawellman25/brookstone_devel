// Demo Code
(function ($, Drupal) {
  'use strict';
    Drupal.behaviors.color_panel = {
      attach: function(context, settings) {
        var default_primary= $(once("colorPBehaviour",':root')).css("--bs-primary");
        var default_secondary= $(once("colorSBehaviour",":root")).css("--bs-secondary");
          $(once("readyBehave", document)).ready(function() {  
          if (typeof(Storage) !== "undefined") {
            // Retrieve
            var primary_color = sessionStorage.getItem("pt-theme-primary-color");
            var secondary_color = sessionStorage.getItem("pt-theme-secondary-color");

            if(primary_color !== "undefined" && secondary_color !== "undefined"){
              $(':root').css('--bs-primary',primary_color);
              $(':root').css('--bs-secondary',secondary_color);
              $('.item-color[data-primary_color="'+primary_color+'"]').filter( $( "[data-secondary_color='"+secondary_color+"']" )).addClass('active');
            }
          }
        });
        $(once('color_panel','.pt-skins-panel .control-panel')).click(function(){
            if($(this).parents('.pt-skins-panel').hasClass('active')){
                $(this).parents('.pt-skins-panel').removeClass('active');
                $('.pt-skins-panel .control-panel .fa').addClass('fa-cog fa-spin');
              $('.pt-skins-panel .control-panel .fa-cog').removeClass('far fa-times');
                
            }
            else{
              $(this).parents('.pt-skins-panel').addClass('active');  
              $('.pt-skins-panel .control-panel .fa').removeClass('fa-cog fa-spin');
              $('.pt-skins-panel .control-panel .fa').addClass('far fa-times');

            } 
        });

        $(once('resetBehavior','#pt-reset-color')).click(function(){
              $(':root').css('--bs-primary',default_primary);
              $(':root').css('--bs-secondary',default_secondary);
              if (typeof(Storage) !== "undefined") {
                sessionStorage.setItem("pt-theme-primary-color", default_primary);
                sessionStorage.setItem("pt-theme-secondary-color", default_secondary);
              }
        });

        $('.pt-skins-panel .item-color').click(function(){
            if($(this).data('primary_color')){
              var category = $(this).data('category');
              var primary_color = $(this).data('primary_color');
              var secondary_color = $(this).data('secondary_color');
              $('.pt-skins-panel .item-color').removeClass('active');
              $(this).addClass('active');
              $(':root').css('--bs-primary',$(this).data('primary_color'));
              $(':root').css('--bs-secondary',$(this).data('secondary_color'));
              if (typeof(Storage) !== "undefined") {
                sessionStorage.setItem("pt-theme-primary-color", $(this).data('primary_color'));
                sessionStorage.setItem("pt-theme-secondary-color", $(this).data('secondary_color'));
              }
            }
        });

        // Page content header class change
        var e = document.getElementById("item_list");
        var currentHome = e.value;
        $("#page_content").addClass(currentHome);
        
        $("#item_list").on('change', function() {
          var currentHeader = $(this).val();
          $("#page_content").removeClass (function (index, css) {
            return (css.match (/(^|\s)header-\S+/g) || []).join(' ');
          });
          $("#page_content").addClass(currentHeader);
        })

        // Header style on change
        $('#item_list').on('change', function() {
          $("#loader").css('display', 'block');
          $('.header_type').removeClass('active');
          var current_header_id = this.value.substr(this.value.indexOf("-") + 1);
          $('#header-'+current_header_id).addClass('active');
          $(this.value).addClass('active');
          $("option[value=" + this.value + "]", this).attr("selected", true).siblings().removeAttr("selected")
            var myVar = setTimeout(showPage, 1000);

            var h3= $("#header-3");
            if(h3.hasClass("active")){
                $("body").addClass("header-3")
            }else{
              $("body").removeClass("header-3")
            }
        });  
        
        function showPage() {
          $("#loader").css('display', 'none');
        }
        
        $(document).ready(function () {
          $('#Check1').click(function(){
            if($('#Check1').is(":checked")){
              var $nav = $("#header-1");
              var $nav2 = $("#header-2");
              var $nav3 = $("#header-3");
              var $nav_custom = $("section.home-slider-1");
              var $nav_custom2 = $("section.home-slider-2");
              var $nav_custom3 = $("section.home-slider-3");
              
              $nav.addClass("navSticky");
              $nav.removeClass("fixed-top");
              $nav_custom.addClass("custom-class");

              $nav2.addClass("navSticky");
              $nav2.removeClass("fixed-top");

              $nav3.addClass("navSticky");
              $nav3.removeClass("fixed-top");
            
            }else{
              var $nav = $("#header-1");
              var $nav2 = $("#header-2");
              var $nav3 = $("#header-3");
              var $nav_custom = $("section.home-slider-1");
              var $nav_custom2 = $("section.home-slider-2");
              var $nav_custom3 = $("section.home-slider-3");

              $nav_custom.removeClass("custom-class");
              $nav.addClass("fixed-top");
              $nav.removeClass("navSticky");
              
              $nav2.addClass("fixed-top");
              $nav2.removeClass("navSticky");
              
              $nav3.addClass("fixed-top");
              $nav3.removeClass("navSticky");
            }
          });
        })


        if($('#Check1').is(":checked")){
          var $nav = $("#header-1");
          var $nav2 = $("#header-2");
          var $nav3 = $("#header-3");
          var $nav_custom = $("section.home-slider-1");
          var $nav_custom2 = $("section.home-slider-2");
          var $nav_custom3 = $("section.home-slider-3");
          
          $nav.addClass("navSticky");
          $nav.removeClass("fixed-top");
          $nav_custom.addClass("custom-class");

          $nav2.addClass("navSticky");
          $nav2.removeClass("fixed-top");

          $nav3.addClass("navSticky");
          $nav3.removeClass("fixed-top");
        
        }else{
          var $nav = $("#header-1");
          var $nav2 = $("#header-2");
          var $nav3 = $("#header-3");
          var $nav_custom = $("section.home-slider-1");
          var $nav_custom2 = $("section.home-slider-2");
          var $nav_custom3 = $("section.home-slider-3");

          $nav_custom.removeClass("custom-class");
          $nav.addClass("fixed-top");
          $nav.removeClass("navSticky");
          
          $nav2.addClass("fixed-top");
          $nav2.removeClass("navSticky");
          
        
          $nav3.addClass("fixed-top");
          $nav3.removeClass("navSticky");
         
        }
     










        // $(function () {
        //   $(document).scroll(function () {
        //     if($('#Check1').is(":checked")){
        //       var $nav = $("#header-1");
        //       var $nav2 = $(".home-slider-1");
        //       $nav.toggleClass("navSticky", $(this).scrollTop() > 0);
        //       $nav.removeClass("fixed-top", $(this).scrollTop() > 0);
        //       var nav3 =(".home-slider-1", $(this).scrollTop() > 0)
        //       // $nav2.addleClass("custom-class", $(this).scrollTop() > 0)
        //     }
        //     else if($('#Check1').is(":not(:checked)")){
        //       var $nav = $("#navSticky");
        //       var $nav2 = $("#navSticky");
        //       $nav.removeClass('navSticky');
        //       $nav2.removeClass('navSticky');
        //     }
        //   });
        // });
        $('.hamber-btn').click(function(){
          $('#headernavbar-1').toggleClass('dnone')
        })
    }
  };

  // document.onreadystatechange = function () {
  //   var state = document.readyState
  //   if (state == 'complete') {
  //          document.getElementById('interactive');
  //          document.getElementById('page-loader').style.visibility="hidden";
  //   }
  // }

})(jQuery, Drupal);

// Demo Code