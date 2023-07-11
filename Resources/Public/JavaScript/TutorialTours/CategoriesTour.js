define(['jquery','bootstrapTour'], function ($) {
    // Instance the tour
    var CategoriesTour = new Tour({
        onStart: function() {
            sessionStorage.setItem("currentTour", "CategoriesTour");
            return true
        },
        onEnd: function() {
            sessionStorage.setItem("currentTour", "");
            return true
        },
        debug: true,
        backdrop: true,
        storage: window.localStorage,
        steps: [
            {
                //path: "/",
                element: "#start-categories-tour",
                title: "Cookie Categories Tour",
                placement: "bottom",
                content: "Categories containing all third-party Providers, such as Youtube, Vimeo, Google Maps, etc.."
            },
            {
                element: "#externalmedia",
                orphan: true,
                title: "Configure 'External Media'.",
                content: "In this Example we configure the Youtube Provider, which is part of the Category 'External Media'."
            },
            {
                path: $("#externalmedia").find(".settings-item-head-right a").attr("href"),
                element: "fieldset:nth-child(5) > div > div > div > div > div > div:nth-child(2)",
                placement: "top",
                orphan: true,
                title: "Add Cookie Services",
                content: "Search for the Youtube provider and add it to the list of Cookie Services"
            },
            {
                element: "fieldset:nth-child(5) > div > div > div > div > div > div:nth-child(1) > div",
                orphan: true,
                placement: "top",
                title: "Youtube Provider added",
                content: "Done, the Youtube Provider is now part of the Category 'External Media'."
            },
            {
                element: "body > div.module > div.module-docheader.t3js-module-docheader > div.module-docheader-bar.module-docheader-bar-buttons.t3js-module-docheader-bar.t3js-module-docheader-bar-buttons > div.module-docheader-bar-column-left > div > button",
                orphan: true,
                title: "Save and close",
                content: "Done, save and close the configuration."
            },
        ]});

    // Initialize the tour
    if(sessionStorage.getItem("currentTour") === "CategoriesTour"){
        CategoriesTour.init();
    }
    $("#start-categories-tour").click(function(e){
        CategoriesTour.init();
        //CategoriesTour.start();
        CategoriesTour.restart();
    });

});

