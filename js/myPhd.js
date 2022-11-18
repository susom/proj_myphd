var myPhd = myPhd || {};

myPhd.sendKey = function(someKey) {
    //Make a copy of this function
    myPhd.log("there is something that gets sent back. can't find the script." + someKey);

    //make a copy of this function
    var proxied = dataEntrySubmit;

    //replace the function
    dataEntrySubmit = function(){
        try {
            var native = window.webkit.messageHandlers.nativeProcessnative.postMessage("submitted");
            console.log('success');
        } catch (err){
            console.log(err.message);
        }

        return proxied.apply(this,arguments);

    };

    var Android = navigator.userAgent.toLowerCase().indexOf("android") >-1;

    if (Android) {
        document.location="js://webview?status=0&myphdkey=" + encodeURIComponent("submitted");
    }

};


myPhd.log = function() {
    console.log.apply(null, arguments);
};

$(document).ready(function(){
    console.log.apply("foo?");
    (function(){
        console.log("hello");
        console.log(this);
    })();
});
