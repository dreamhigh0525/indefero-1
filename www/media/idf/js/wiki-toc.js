$(document).ready(function() {
    $(":header", "#wiki-content").map(function(index) {
        this.id = "wikititle_" + index;
        $("<a href='#" + this.id + "'>" + jQuery.fn.text([this]) + "</a>")
            .addClass("wiki-" + this.tagName.toLowerCase())
            .appendTo('#wiki-toc-content');
    });
    if ($('#wiki-toc-content').size() < 2)
        $('#wiki-toc').hide();
});

