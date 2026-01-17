function setCookie(name,value,days) {
    var expires = "";
    if (days) {
        var date = new Date();
        date.setTime(date.getTime() + (days*24*60*60*1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "")  + expires + "; path=/";
}

function getCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');
    for(var i=0;i < ca.length;i++) {
        var c = ca[i];
        while (c.charAt(0)==' ') c = c.substring(1,c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
    }
    return null;
}

function eraseCookie(name) {   
    document.cookie = name +'=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
}

if (getCookie('lm') === 'true') {
    document.body.classList.add('light');
}

document.getElementById('lm-btn').addEventListener('click', function() {
    document.body.classList.toggle('light');
    const isDark = !document.body.classList.contains('light');
    const lmValue = !isDark;
    setCookie('lm', lmValue, 365);
    fetch('https://fuck.buage.dev/lmode.php?lm=' + lmValue);
});

document.getElementById('lookup-btn').addEventListener('click', function() {
    fetch('https://hylookapi.buage.dev/lookup?user=' + document.getElementById('username-input').value)
})

fetch('https://fuck.buage.dev/stats.php', { method: 'GET' })
.then(res => res.json())
.then(data => {
    if (!data.ok) return;
    document.getElementById('totalViews').textContent = data.totals.visits + ' views';
    document.getElementById('totalLm').textContent = data.lightmode.enabled + ' visitors';
});