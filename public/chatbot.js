/**
 * SportsSync Chatbot — chatbot.js (v3 — Clean Rewrite)
 * Drop into: public/chatbot.js
 * Vanilla JS only. No frameworks.
 */
(function () {
  'use strict';

  /* ───────────────────────────────────────────
     CONFIG
  ─────────────────────────────────────────── */
  var API_URL        = '/chatbot_api.php';
  var SPAM_THRESHOLD = 4;      // rapid clicks to trigger reaction
  var SPAM_WINDOW    = 2000;   // ms window to count clicks
  var IDLE_MIN       = 22000;
  var IDLE_MAX       = 42000;

  /* ───────────────────────────────────────────
     STATE  (flat, no nested objects fighting each other)
  ─────────────────────────────────────────── */
  var lang            = localStorage.getItem('cb_lang') || null;
  var isOpen          = false;
  var isTyping        = false;
  var clickCount      = 0;
  var clickResetTimer = null;
  var spamLevel       = 0;      // 0=normal 1=annoyed 2=max
  var isResting       = false;  // true while "Wait lang" cooldown runs
  var restTimer       = null;
  var currentMood     = 'normal';
  var moodTimer       = null;
  var idleTimer       = null;
  var toastHideTimer  = null;
  var lastInteraction = Date.now();

  /* ───────────────────────────────────────────
     DOM (assigned in init)
  ─────────────────────────────────────────── */
  var trigger, face, chatWindow, closeBtn,
      langScreen, chatScreen, messages,
      input, sendBtn, resetLangBtn, badge,
      avatarFace, headerEl, moodBar, toastEl;

  /* ───────────────────────────────────────────
     EMOJI FACES
  ─────────────────────────────────────────── */
  var MOOD_FACE = {
    normal:  '😏',
    happy:   '😄',
    annoyed: '😤',
    sleepy:  '😴',
    curious: '👀',
    resting: '😮‍💨',
  };

  var IDLE_FACES   = ['😏','😎','🤖','⚡','👀','😆','😒','🧐'];
  var idleFaceIdx  = 0;
  var idleFaceTimer = null;

  /* ───────────────────────────────────────────
     MOOD CSS
  ─────────────────────────────────────────── */
  var MOOD_BTN_CLASS = {
    normal:  'cb-mood-normal',
    happy:   'cb-mood-happy',
    annoyed: 'cb-mood-annoyed',
    sleepy:  'cb-mood-sleepy',
    curious: 'cb-mood-curious',
    resting: 'cb-mood-annoyed', // reuse red colour
  };
  var MOOD_BAR_CLASS = {
    normal:  'mood-normal',
    happy:   'mood-happy',
    annoyed: 'mood-annoyed',
    sleepy:  'mood-sleepy',
    curious: 'mood-curious',
    resting: 'mood-annoyed',
  };
  var ALL_BTN_MOODS = ['cb-mood-normal','cb-mood-happy','cb-mood-annoyed','cb-mood-sleepy','cb-mood-curious'];
  var ALL_BAR_MOODS = ['mood-normal','mood-happy','mood-annoyed','mood-sleepy','mood-curious'];

  var MOOD_STATUS = {
    normal:  { en: 'Online — Laging handa! 😎',  tl: 'Online — Laging handa! 😎'  },
    happy:   { en: 'Excited mode ON! 🔥',          tl: 'Excited mode ON! 🔥'         },
    annoyed: { en: 'Medyo naiinis… 😤',            tl: 'Medyo naiinis na… 😤'        },
    sleepy:  { en: 'Antok mode… 😴',              tl: 'Antok na… 😴'               },
    curious: { en: 'May tanong ka ba? 🤔',         tl: 'May tanong ka ba? 🤔'        },
    resting: { en: 'Pahinga muna... 😮‍💨',          tl: 'Pahinga muna... 😮‍💨'        },
  };

  /* ───────────────────────────────────────────
     RESPONSES
  ─────────────────────────────────────────── */
  var SPAM_0 = {
    en: ['HOY! 😤', 'Hey! Calm down! 😅', 'ANU BA?! 😤'],
    tl: ['HOY! 😤', 'Huy! Tahan ka! 😅',  'ANU BA?! 😤'],
  };
  var SPAM_1 = {
    en: ['ANG KULIT MO 😭 Isa-isa lang!','Grabe ka non-stop! 😤','Sige pa, pindot pa more 😒','MAGPAHINGA KA MUNA! 💀'],
    tl: ['ANG KULIT MO 😭 Isang click lang!','Grabe ka talaga! 😤','Sige pa, pindot pa more 😒','MAGPAHINGA KA NAMAN! 💀'],
  };
  var SPAM_2 = {
    en: ['🚨 BAKIT?! WHY?! STOP! 🚨','OK SIGE. HINDI NA KITA SASAGUTIN. 😤','Fine. FINEEEE. 😭😤'],
    tl: ['🚨 BAKIT?! TIGILIN MO NA! 🚨','OK. HINDI NA KITA SASAGUTIN. 😤','Sige lang. SIGEEEE. 😭😤'],
  };
  var REST_MSG = {
    en: 'Wait lang! Pahinga muna ako sandali... 😮‍💨',
    tl: 'Wait lang ha... pahinga muna ako sandali. 😮‍💨',
  };
  var BACK_MSG = {
    en: 'OK na! 😤➡️😏 Back na ako. Anong kailangan mo, boss?',
    tl: 'OK na ko! 😤➡️😏 Nandito na ulit. Ano kailangan mo, boss?',
  };
  var IDLE_TOASTS = {
    en: ['Uy, buhay ka pa pala 😏','Try mo tanungin ako tungkol sa players 😆','Wala ka bang ginagawa? 😭','Pssst… may bagong match results! 👀','I know things. Sports things. Ask me! 😎'],
    tl: ['Uy, buhay ka pa pala 😏','Subukan mo akong tanungin 😆','Wala ka bang gagawin? 😭','Pssst… may bagong results na! 👀','Marunong akong mag-Sports. Tanong ka! 😎'],
  };
  var IDLE_CHAT = {
    en: ['Uy, nandyan ka pa pala! Kausapin mo naman ako 😏','Try: "latest match" or "standings" 😆','Hoy… hello?? 👋'],
    tl: ['Uy, nandito ka pa pala! Kausapin mo naman ako 😏','I-try: "latest match" o "standings" 😆','Hoy… hello?? 👋'],
  };
  var GREETINGS = {
    en: ["Yo! 👋 I'm **SyncBot**! Ask me about matches, players, or standings! 😎","Uy, dumating ka na! 🎉 I got you, boss! ⚡","Hoy! Welcome! 👋 Anong kailangan mo? 😏"],
    tl: ["Hoy! 👋 Ako si **SyncBot**! Tanong na! 😎","Uy, dumating ka! 🎉 Anong kailangan mo? 😏","Boss! Nandito na ako! ⚡ Tanong ka na! 🔥"],
  };
  var FALLBACKS = {
    en: ["Hmm… di ko gets 🤔 Try: *player [name]*, *standings*, *latest match*, or *help*","Ay sus, ano daw? 😂 Type *help*!","Nani?? 👀 Type *help*!"],
    tl: ["Hala, hindi ko gets 🤔 Subukan: *player [pangalan]*, *standings*, o *help*","Ay nako, ano daw?! 😂 Type *help*!","Nani?? 👀 I-type ang *help*!"],
  };
  var NO_DATA = {
    en: ["Wala akong makitang data… 😭 Baka typo? Try again!","404: Not found! 💀 Check the spelling.","Hmm, empty talaga 🤷"],
    tl: ["Wala akong makitang data… 😭 Baka typo? Subukan ulit!","404: Not found! 💀 Tsek ang spelling.","Hmm, wala talaga 🤷"],
  };
  var HELP_TEXT = {
    en: "Here's what I can do, boss! 😎\n\n🔍 **Player info** — \"player Juan\"\n👥 **Team info** — \"team Red Warriors\"\n🏆 **Standings** — \"standings\"\n⚽ **Latest matches** — \"latest match\"\n🏀 **Basketball** — \"basketball results\"\n🏐 **Volleyball** — \"volleyball results\"\n🏸 **Badminton** — \"badminton results\"\n🏓 **Table Tennis** — \"table tennis results\"\n🎯 **Darts** — \"darts results\"",
    tl: "Eto ang kaya kong gawin! 😎\n\n🔍 **Player info** — \"player Juan\"\n👥 **Team info** — \"team Red Warriors\"\n🏆 **Standings** — \"standings\"\n⚽ **Latest matches** — \"latest match\"\n🏀 **Basketball** — \"basketball results\"\n🏐 **Volleyball** — \"volleyball results\"\n🏸 **Badminton** — \"badminton results\"\n🏓 **Table Tennis** — \"table tennis results\"\n🎯 **Darts** — \"darts results\"",
  };

  /* ───────────────────────────────────────────
     UTILS
  ─────────────────────────────────────────── */
  function rand(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
  function gl()      { return lang || 'en'; }

  function mdToHtml(t) {
    return t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
            .replace(/\*(.+?)\*/g,'<em>$1</em>')
            .replace(/\n/g,'<br>');
  }

  function scrollBottom() {
    if (messages) messages.scrollTop = messages.scrollHeight;
  }

  /* ───────────────────────────────────────────
     MOOD — sets colour + status text only.
     NEVER touches the trigger face emoji directly.
     NEVER touches trigger.className.
  ─────────────────────────────────────────── */
  function setMood(mood, durationMs) {
    if (!MOOD_BTN_CLASS[mood]) mood = 'normal';
    currentMood = mood;

    // Swap button mood class only
    if (trigger) {
      ALL_BTN_MOODS.forEach(function(c){ trigger.classList.remove(c); });
      trigger.classList.add(MOOD_BTN_CLASS[mood]);
    }

    // Avatar face inside header
    if (avatarFace) avatarFace.textContent = MOOD_FACE[mood];

    // Header status text
    if (headerEl) {
      headerEl.setAttribute('data-mood', mood);
      var statusEl = headerEl.querySelector('.cb-header-status');
      if (statusEl) {
        var dot = statusEl.querySelector('.cb-status-dot');
        var txt = MOOD_STATUS[mood][gl()];
        statusEl.innerHTML = '';
        if (dot) statusEl.appendChild(dot);
        statusEl.appendChild(document.createTextNode(' ' + txt));
      }
    }

    // Mood bar
    if (moodBar) {
      ALL_BAR_MOODS.forEach(function(c){ moodBar.classList.remove(c); });
      moodBar.classList.add(MOOD_BAR_CLASS[mood]);
    }

    // Window annoyed tint
    if (chatWindow) {
      chatWindow.classList.toggle('cb-window-annoyed', mood === 'annoyed' || mood === 'resting');
    }

    // Auto-reset
    if (moodTimer) clearTimeout(moodTimer);
    if (durationMs && mood !== 'normal') {
      moodTimer = setTimeout(function(){ setMood('normal'); }, durationMs);
    }
  }

  /* ───────────────────────────────────────────
     TRIGGER FACE — completely separate from mood.
     Only two sources write to it:
       setTriggerFace()  ← called explicitly
       toggleWindow()    ← sets ✕ or restores
  ─────────────────────────────────────────── */
  function setTriggerFace(emoji) {
    if (face && !isOpen) face.textContent = emoji;
  }

  /* ───────────────────────────────────────────
     IDLE FACE ROTATION
  ─────────────────────────────────────────── */
  function scheduleFaceRotation() {
    clearTimeout(idleFaceTimer);
    idleFaceTimer = setTimeout(function() {
      // Always rotate UNLESS chat is open (shows ✕) or bot is resting
      if (!isOpen && !isResting) {
        idleFaceIdx = (idleFaceIdx + 1) % IDLE_FACES.length;
        if (face) face.textContent = IDLE_FACES[idleFaceIdx];
      }
      scheduleFaceRotation();
    }, 5000 + Math.random() * 4000);
  }

  /* ───────────────────────────────────────────
     TOAST
  ─────────────────────────────────────────── */
  function showToast(text, type, ms) {
    if (!toastEl) return;
    ms   = ms   || 4000;
    type = type || 'idle';
    clearTimeout(toastHideTimer);
    toastEl.innerHTML   = mdToHtml(text);
    toastEl.className   = 'cb-toast-show cb-toast-' + type;
    toastEl.onclick     = function(){ hideToast(); if (!isOpen) openWindow(); };
    toastHideTimer = setTimeout(function(){
      toastEl.classList.remove('cb-toast-show');
    }, ms);
  }

  function hideToast() {
    if (!toastEl) return;
    clearTimeout(toastHideTimer);
    toastEl.classList.remove('cb-toast-show');
  }

  /* ───────────────────────────────────────────
     SPARKS
  ─────────────────────────────────────────── */
  function burstSparks(n, colors) {
    if (!trigger) return;
    var r  = trigger.getBoundingClientRect();
    var cx = r.left + r.width  / 2;
    var cy = r.top  + r.height / 2;
    for (var i = 0; i < n; i++) {
      (function(){
        var s   = document.createElement('div');
        s.className = 'cb-spark';
        var a   = Math.random() * Math.PI * 2;
        var d   = 40 + Math.random() * 60;
        var sz  = 5 + Math.random() * 7;
        var c   = colors[Math.floor(Math.random() * colors.length)];
        var dl  = Math.random() * 120;
        s.style.cssText = 'left:'+(cx-sz/2)+'px;top:'+(cy-sz/2)+'px;width:'+sz+'px;height:'+sz+'px;'
          +'background:'+c+';--sx:'+(Math.cos(a)*d)+'px;--sy:'+(Math.sin(a)*d)+'px;'
          +'animation-delay:'+dl+'ms;box-shadow:0 0 '+(sz*1.5)+'px '+c+';';
        document.body.appendChild(s);
        setTimeout(function(){ s.remove(); }, 800 + dl);
      })();
    }
  }

  function addRipple() {
    if (!trigger) return;
    var old = trigger.querySelector('.cb-ripple');
    if (old) old.remove();
    var r = document.createElement('span');
    r.className = 'cb-ripple';
    trigger.appendChild(r);
    setTimeout(function(){ r.remove(); }, 600);
  }

  /* ───────────────────────────────────────────
     WINDOW OPEN / CLOSE
     Single source of truth — nothing else
     should touch isOpen or trigger face.
  ─────────────────────────────────────────── */
  function openWindow() {
    if (isOpen) return;
    isOpen = true;
    chatWindow.classList.add('cb-open');
    if (face) face.textContent = '✕';  // ← only place ✕ is set
    badge.classList.remove('visible');
    hideToast();
    localStorage.setItem('cb_opened', '1');

    if (!lang) {
      showLangScreen();
    } else {
      showChatScreen();
      if (messages.children.length === 0) {
        setMood('happy', 3000);
        setTimeout(function(){
          appendMsg(rand(GREETINGS[lang]), 'bot',
            makeChips(gl() === 'tl'
              ? ['Standings','Latest match','Hanapin player','Help']
              : ['Standings','Latest match','Find player','Help']));
        }, 400);
      }
      setTimeout(function(){ if (input) input.focus(); }, 100);
    }
  }

  function closeWindow() {
    if (!isOpen) return;
    isOpen = false;
    chatWindow.classList.remove('cb-open');
    // Restore face to current idle face (NOT locked to 😏)
    if (face) face.textContent = IDLE_FACES[idleFaceIdx];
  }

  function toggleWindow() {
    if (isOpen) closeWindow(); else openWindow();
  }

  /* ───────────────────────────────────────────
     SPAM CLICK REACTION
     Reacts with animation + message.
     NEVER blocks openWindow/closeWindow.
     NEVER sets trigger.className.
  ─────────────────────────────────────────── */
  function triggerSpamReaction() {
    addRipple();

    if (spamLevel === 0) {
      // Level 0 — mild shake
      trigger.classList.add('cb-shake');
      setTimeout(function(){ trigger.classList.remove('cb-shake'); }, 450);
      setMood('annoyed', 3000);
      burstSparks(8, ['#FF8C00','#FFD700','#fff']);
      var r0 = rand(SPAM_0[gl()]);
      showToast(r0, 'annoyed', 3000);
      if (isOpen) appendMsg(r0, 'bot', null, true);
      spamLevel = 1;

    } else if (spamLevel === 1) {
      // Level 1 — pop + flash
      trigger.classList.add('cb-annoyed-pop');
      setTimeout(function(){ trigger.classList.remove('cb-annoyed-pop'); }, 400);
      setMood('annoyed', 5000);
      burstSparks(14, ['#FF3B30','#FF8C00','#FFD700']);
      var r1 = rand(SPAM_1[gl()]);
      showToast(r1, 'annoyed', 4500);
      if (isOpen) {
        appendMsg(r1, 'bot', null, true);
        flashWindow();
      }
      spamLevel = 2;

    } else {
      // Level 2 — max rage, then rest
      if (isResting) return; // already resting, ignore

      trigger.classList.add('cb-shake');
      setTimeout(function(){ trigger.classList.remove('cb-shake'); }, 500);
      setMood('resting');   // stays until rest ends
      burstSparks(22, ['#FF3B30','#ff6b35','#FFD700','#fff']);

      var r2 = rand(SPAM_2[gl()]);
      showToast(r2, 'annoyed', 3000);
      if (isOpen) { appendMsg(r2, 'bot', null, true); flashWindow(); }

      // Small pause then show "Wait lang" message
      setTimeout(function(){
        var restTxt = REST_MSG[gl()];
        showToast(restTxt, 'annoyed', 5500);
        if (isOpen) appendMsg(restTxt, 'bot', null, true);
        // Lock the trigger face to 😮‍💨 during rest
        if (face && !isOpen) face.textContent = '😮‍💨';
      }, 800);

      // Mark resting — only disables face rotation, nothing else
      isResting = true;
      clearTimeout(restTimer);
      restTimer = setTimeout(function(){
        // Calm down fully
        isResting   = false;
        spamLevel   = 0;
        setMood('normal');
        // Restore face
        idleFaceIdx = 0;
        if (face && !isOpen) face.textContent = IDLE_FACES[0];
        // Clear any inline transform left by old animation code
        if (face) face.style.transform = '';
        // Send comeback message
        var backTxt = BACK_MSG[gl()];
        showToast(backTxt, 'idle', 4000);
        if (isOpen) appendMsg(backTxt, 'bot');
      }, 5000);
    }
  }

  function flashWindow() {
    if (!chatWindow) return;
    chatWindow.classList.add('cb-flash-annoyed');
    setTimeout(function(){ chatWindow.classList.remove('cb-flash-annoyed'); }, 700);
  }

  /* ───────────────────────────────────────────
     TRIGGER CLICK HANDLER
     Rule: spam reaction fires OR toggle fires — never both.
     Resting does NOT block the window — user can still open.
  ─────────────────────────────────────────── */
  function handleTriggerClick() {
    lastInteraction = Date.now();
    hideToast();
    addRipple();

    clickCount++;
    clearTimeout(clickResetTimer);
    clickResetTimer = setTimeout(function(){
      clickCount = 0;
    }, SPAM_WINDOW);

    if (clickCount >= SPAM_THRESHOLD) {
      clickCount = 0;  // reset immediately
      triggerSpamReaction();
      return;          // don't toggle this click
    }

    toggleWindow();
  }

  /* ───────────────────────────────────────────
     IDLE PERSONALITY
  ─────────────────────────────────────────── */
  function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(fireIdle, IDLE_MIN + Math.random() * (IDLE_MAX - IDLE_MIN));
  }

  function fireIdle() {
    var elapsed = Date.now() - lastInteraction;
    if (elapsed > 90000) {
      setMood('sleepy', 20000);
    } else {
      setMood('curious', 8000);
    }
    if (isOpen && lang) {
      appendMsg(rand(IDLE_CHAT[gl()]), 'bot');
    } else {
      showToast(rand(IDLE_TOASTS[gl()]), 'idle', 5000);
    }
    resetIdleTimer();
  }

  /* ───────────────────────────────────────────
     LANGUAGE SCREEN
  ─────────────────────────────────────────── */
  function showLangScreen() {
    langScreen.classList.remove('cb-hidden');
    chatScreen.classList.add('cb-hidden');
  }

  function showChatScreen() {
    langScreen.classList.add('cb-hidden');
    chatScreen.classList.remove('cb-hidden');
    if (input) input.focus();
  }

  function selectLang(l) {
    lang = l;
    localStorage.setItem('cb_lang', l);
    showChatScreen();
    setMood('happy', 4000);
    setTimeout(function(){
      appendMsg(rand(GREETINGS[l]), 'bot',
        makeChips(l === 'tl'
          ? ['Standings','Latest match','Hanapin player','Help']
          : ['Standings','Latest match','Find player','Help']));
    }, 400);
    lastInteraction = Date.now();
    resetIdleTimer();
  }

  /* ───────────────────────────────────────────
     MESSAGE RENDERING
  ─────────────────────────────────────────── */
  function appendMsg(text, who, extra, annoyed) {
    var d = document.createElement('div');
    d.className = 'cb-msg cb-msg-' + who;
    if (annoyed) d.classList.add('cb-msg-annoyed');
    d.innerHTML = mdToHtml(text);
    if (extra) d.appendChild(extra);
    messages.appendChild(d);
    scrollBottom();
    return d;
  }

  function showTyping() {
    isTyping = true;
    if (sendBtn) sendBtn.disabled = true;
    var d = document.createElement('div');
    d.className = 'cb-typing'; d.id = 'cb-typing-indicator';
    d.innerHTML = '<span></span><span></span><span></span>';
    messages.appendChild(d);
    scrollBottom();
  }

  function hideTyping() {
    isTyping = false;
    if (sendBtn) sendBtn.disabled = false;
    var d = document.getElementById('cb-typing-indicator');
    if (d) d.remove();
  }

  function botReply(text, extra, delay, annoyed) {
    delay = (delay !== undefined) ? delay : (700 + Math.random() * 600);
    showTyping();
    setTimeout(function(){
      hideTyping();
      appendMsg(text, 'bot', extra, annoyed);
    }, delay);
  }

  function makeCard(title, rows) {
    var c = document.createElement('div'); c.className = 'cb-card';
    var t = document.createElement('div'); t.className = 'cb-card-title';
    t.textContent = title; c.appendChild(t);
    rows.forEach(function(r){
      var row = document.createElement('div'); row.className = 'cb-card-row';
      row.innerHTML = '<span>'+r[0]+'</span><span>'+r[1]+'</span>';
      c.appendChild(row);
    });
    return c;
  }

  function makeChips(labels) {
    var w = document.createElement('div'); w.className = 'cb-chips';
    labels.forEach(function(lbl){
      var b = document.createElement('button'); b.className = 'cb-chip';
      b.textContent = lbl;
      b.addEventListener('click', function(){ handleUserMessage(lbl); });
      w.appendChild(b);
    });
    return w;
  }

  /* ───────────────────────────────────────────
     INTENT DETECTION
  ─────────────────────────────────────────── */
  var INTENTS = [
    { p: /\bhelp\b|commands|ano.*kaya mo|what can you do/i, i: 'help'      },
    { p: /\bplayer\b|manlalaro|sino.*player|player.*sino/i, i: 'player'    },
    { p: /\bteam\b|koponan|grupo/i,                          i: 'team'      },
    { p: /stand(ing)?s?|leaderboard|ranking|top team/i,      i: 'standings' },
    { p: /latest|recent|last.*match|pinakabago|result/i,     i: 'latest'    },
    { p: /basketball|bball/i,                                i: 'sport_bb'  },
    { p: /volleyball|volley/i,                               i: 'sport_vb'  },
    { p: /badminton/i,                                       i: 'sport_bd'  },
    { p: /table.*tennis|pingpong|ping.*pong/i,               i: 'sport_tt'  },
    { p: /darts?/i,                                          i: 'sport_dr'  },
    { p: /\bhello\b|\bhi\b|\bhey\b|kumusta|kamusta/i,        i: 'greet'     },
    { p: /thank|salamat|ty\b/i,                              i: 'thanks'    },
  ];

  function detectIntent(text) {
    for (var k = 0; k < INTENTS.length; k++) {
      if (INTENTS[k].p.test(text)) return INTENTS[k].i;
    }
    return 'unknown';
  }

  function extractAfter(text, kws) {
    for (var k = 0; k < kws.length; k++) {
      var m = text.match(new RegExp(kws[k] + '\\s+(.+)', 'i'));
      if (m && m[1].trim().length > 1) return m[1].trim();
    }
    return null;
  }

  /* ───────────────────────────────────────────
     API
  ─────────────────────────────────────────── */
  function callApi(params) {
    var qs = Object.keys(params).map(function(k){
      return encodeURIComponent(k)+'='+encodeURIComponent(params[k]);
    }).join('&');
    return fetch(API_URL+'?'+qs, {credentials:'same-origin'}).then(function(r){ return r.json(); });
  }

  /* ───────────────────────────────────────────
     INTENT HANDLERS
  ─────────────────────────────────────────── */
  function handlePlayer(query) {
    var name = extractAfter(query, ['player','manlalaro','sino si','sino ang']);
    if (!name) { botReply(gl()==='tl' ? 'Anong pangalan? I-type: **player [pangalan]** 😊' : 'Which player? Type: **player [name]** 😊'); return; }
    callApi({action:'player', name:name}).then(function(data){
      if (!data||data.error||!data.player){ botReply(rand(NO_DATA[gl()])); return; }
      var p    = data.player;
      var rows = [
        [gl()==='tl'?'Buong pangalan':'Full Name', p.full_name],
        [gl()==='tl'?'Koponan':'Team', p.team_name||'—'],
      ];
      if (p.sport)        rows.push(['Sport', p.sport]);
      if (p.games_played) rows.push([gl()==='tl'?'Laro':'Games', p.games_played]);
      if (data.stats) {
        var s = data.stats;
        if (s.pts!==undefined) rows.push(['PTS',s.pts]);
        if (s.reb!==undefined) rows.push(['REB',s.reb]);
        if (s.ast!==undefined) rows.push(['AST',s.ast]);
        if (s.blk!==undefined) rows.push(['BLK',s.blk]);
        if (s.stl!==undefined) rows.push(['STL',s.stl]);
      }
      setMood('happy',3000);
      botReply(gl()==='tl'?'Nahanap ko siya! 🎉 Info ni **'+p.full_name+'**:':'Found them! 🎉 Info for **'+p.full_name+'**:', makeCard('🏅 '+p.full_name, rows));
    }).catch(function(){ botReply(rand(FALLBACKS[gl()])); });
  }

  function handleTeam(query) {
    var name = extractAfter(query, ['team','koponan','grupo']);
    if (!name) { botReply(gl()==='tl' ? 'Anong team? I-type: **team [pangalan]** 😊' : 'Which team? Type: **team [name]** 😊'); return; }
    callApi({action:'team', name:name}).then(function(data){
      if (!data||data.error||!data.players||!data.players.length){ botReply(rand(NO_DATA[gl()])); return; }
      var lines = data.players.map(function(p){ return p.full_name; }).join(', ');
      setMood('happy',3000);
      botReply(gl()==='tl'?'Nahanap ko ang **'+data.team_name+'**! 🔥':'Found **'+data.team_name+'**! 🔥',
        makeCard('👥 '+data.team_name, [[gl()==='tl'?'Bilang':'Players', data.players.length],['Roster',lines]]));
    }).catch(function(){ botReply(rand(FALLBACKS[gl()])); });
  }

  function handleStandings() {
    callApi({action:'standings'}).then(function(data){
      if (!data||data.error||!data.standings||!data.standings.length){ botReply(rand(NO_DATA[gl()])); return; }
      var rows = data.standings.slice(0,8).map(function(s,i){ return [(i+1)+'. '+s.team_name, s.wins+'W – '+s.losses+'L']; });
      setMood('happy',3000);
      botReply(gl()==='tl'?'🏆 Eto ang basketball standings! Sino kaya? 🤔':'🏆 Current basketball standings! Who takes the crown? 🤔', makeCard('📊 Standings', rows));
    }).catch(function(){ botReply(rand(FALLBACKS[gl()])); });
  }

  function handleLatest(sport) {
    var params = {action:'latest'}; if (sport) params.sport = sport;
    callApi(params).then(function(data){
      if (!data||data.error||!data.matches||!data.matches.length){ botReply(rand(NO_DATA[gl()])); return; }
      var rows = data.matches.slice(0,5).map(function(m){
        var sc = (m.team_a_score!==undefined&&m.team_a_score!==null)
          ? m.team_a_name+' '+m.team_a_score+' – '+m.team_b_score+' '+m.team_b_name
          : m.team_a_name+' vs '+m.team_b_name;
        return [m.sport||'Basketball', sc+(m.winner?' 🏆 '+m.winner:'')];
      });
      setMood('happy',3000);
      botReply(gl()==='tl'?'🔥 Pinakabagong laro!':'🔥 Latest match results!', makeCard('📋 Recent Matches', rows));
    }).catch(function(){ botReply(rand(FALLBACKS[gl()])); });
  }

  /* ───────────────────────────────────────────
     MAIN MESSAGE HANDLER
  ─────────────────────────────────────────── */
  function handleUserMessage(text) {
    text = (text||'').trim();
    if (!text) return;
    lastInteraction = Date.now();
    resetIdleTimer();
    appendMsg(text, 'user');
    if (input){ input.value=''; input.style.height='38px'; }

    switch (detectIntent(text)) {
      case 'greet':
        setMood('happy',4000);
        botReply(rand(GREETINGS[lang||'en']), makeChips(gl()==='tl'?['Standings','Latest match','Player search','Help']:['Standings','Latest match','Player search','Help']));
        break;
      case 'thanks':
        setMood('happy',3000);
        botReply(gl()==='tl'?'Wagas! Anytime, boss! 😎⚡':'Anytime, boss! 😎⚡ Anything else?');
        break;
      case 'help':      botReply(HELP_TEXT[gl()]);     break;
      case 'player':    handlePlayer(text);             break;
      case 'team':      handleTeam(text);               break;
      case 'standings': handleStandings();              break;
      case 'latest':    handleLatest(null);             break;
      case 'sport_bb':  handleLatest('basketball');     break;
      case 'sport_vb':  handleLatest('volleyball');     break;
      case 'sport_bd':  handleLatest('badminton');      break;
      case 'sport_tt':  handleLatest('table_tennis');   break;
      case 'sport_dr':  handleLatest('darts');          break;
      default:
        botReply(rand(FALLBACKS[gl()]), makeChips(gl()==='tl'?['Help','Standings','Latest match']:['Help','Standings','Latest match']));
    }
  }

  /* ───────────────────────────────────────────
     INIT
  ─────────────────────────────────────────── */
  function init() {
    trigger      = document.getElementById('cb-trigger');
    face         = document.getElementById('cb-trigger-face');
    chatWindow   = document.getElementById('cb-window');
    closeBtn     = document.getElementById('cb-close');
    langScreen   = document.getElementById('cb-lang-screen');
    chatScreen   = document.getElementById('cb-chat-screen');
    messages     = document.getElementById('cb-messages');
    input        = document.getElementById('cb-input');
    sendBtn      = document.getElementById('cb-send');
    resetLangBtn = document.getElementById('cb-reset-lang');
    badge        = document.getElementById('cb-badge');
    avatarFace   = document.getElementById('cb-avatar-face');
    headerEl     = document.getElementById('cb-header');
    moodBar      = document.getElementById('cb-mood-bar');
    toastEl      = document.getElementById('cb-toast');

    // ── DIAGNOSTIC: log every missing element ──
    var missing = [];
    if (!trigger)      missing.push('#cb-trigger');
    if (!face)         missing.push('#cb-trigger-face');
    if (!chatWindow)   missing.push('#cb-window');
    if (!closeBtn)     missing.push('#cb-close');
    if (!langScreen)   missing.push('#cb-lang-screen');
    if (!chatScreen)   missing.push('#cb-chat-screen');
    if (!messages)     missing.push('#cb-messages');
    if (!input)        missing.push('#cb-input');
    if (!sendBtn)      missing.push('#cb-send');
    if (!badge)        missing.push('#cb-badge');
    if (!toastEl)      missing.push('#cb-toast');
    if (missing.length) {
      console.error('SyncBot: Missing elements:', missing.join(', '));
      console.error('SyncBot: Make sure the chatbot HTML snippet is in landing.blade.php');
    }
    if (!trigger) return; // can't do anything without trigger

    // Events
    trigger.addEventListener('click', handleTriggerClick);
    closeBtn.addEventListener('click', closeWindow);
    var langEn = document.getElementById('cb-lang-en');
    var langTl = document.getElementById('cb-lang-tl');
    if (langEn) langEn.addEventListener('click', function(){ selectLang('en'); });
    if (langTl) langTl.addEventListener('click', function(){ selectLang('tl'); });
    if (resetLangBtn) resetLangBtn.addEventListener('click', function(){
      lang = null;
      localStorage.removeItem('cb_lang');
      if (messages) messages.innerHTML = '';
      showLangScreen();
      setMood('normal');
    });
    if (sendBtn) sendBtn.addEventListener('click', function(){ handleUserMessage(input.value); });
    input.addEventListener('keydown', function(e){
      if (e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); handleUserMessage(input.value); }
    });
    input.addEventListener('input', function(){
      this.style.height='38px';
      this.style.height=Math.min(this.scrollHeight,100)+'px';
    });
    document.addEventListener('click', function(e){
      if (isOpen&&!chatWindow.contains(e.target)&&!trigger.contains(e.target)) closeWindow();
    });

    // First-visit badge
    if (!localStorage.getItem('cb_opened')) {
      setTimeout(function(){
        if (!isOpen){
          badge.textContent='1'; badge.classList.add('visible');
          showToast(gl()==='tl'?'👋 Hoy! Kausapin mo ako!':'👋 Hey! Chat with me!','idle',5000);
        }
      }, 4000);
    }

    // Initial state
    setMood('normal');
    if (face) {
      face.textContent = IDLE_FACES[0];  // set once, idle rotation takes over
      face.style.transform = '';          // clear any stale inline transform
    }

    scheduleFaceRotation();
    resetIdleTimer();
  }

  if (document.readyState==='loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }


  /* ================================================================
     ABOUT BOT — merged into chatbot.js
     All vars/functions namespaced with ab_ prefix.
     Completely independent from chatbot widget above.
  ================================================================ */

  (function(){
    'use strict';

    /* ── Data ── */
    var AB_CREW = [
      { name:'Joanner Relleve',      role:'Team Lead / System Architect', emoji:'J' },
      { name:'Kurt Oliver Pagatpat', role:'Backend (PHP/MySQL)',           emoji:'K' },
      { name:'Ludwig Agawin',        role:'Real-time Sync (SSOT)',         emoji:'L' },
      { name:'Realyn Mitra',         role:'UI/UX Design',                  emoji:'R' },
      { name:'Joanna Oademis',       role:'Frontend Dev',                  emoji:'J' },
      { name:'Aliah Lim',            role:'QA & Testing',                  emoji:'A' },
      { name:'Francis Genorilla',    role:'Data & Analytics',              emoji:'F' },
    ];

    var AB_CREW_REACTIONS = [
      'Solid to sila ha \u{1F60E}',
      'Teamwork malala \u{1F4AF}',
      'Dito nagkaalaman kung sino puyat \u{1F62D}',
      'Respect talaga \u{1F64C}',
      'Grabe yung dedication \u{1F620}',
      'Probably drank 10 cups of coffee para dito \u{1F602}',
      'Silent hero ng system na ito \u{1F3C5}',
    ];

    var AB_CONTRIBUTIONS = [
      { icon:'\u{1F3D7}', label:'System Design',          key:'design'    },
      { icon:'\u26A1',    label:'Real-time Sync (SSOT)',   key:'sync'      },
      { icon:'\u{1F3A8}', label:'UI/UX',                   key:'uiux'      },
      { icon:'\u{1F418}', label:'Backend (PHP/MySQL)',      key:'backend'   },
      { icon:'\u{1F9EA}', label:'Testing & QA',            key:'qa'        },
      { icon:'\u{1F4CA}', label:'Analytics',               key:'analytics' },
    ];

    var AB_CONTRIB_COMMENTS = {
      design:    ['Ito yung pinaka-pinagisipan \u{1F620}','Dito nagsimula lahat ng gusto at hindi gusto \u{1F605}','Architecture decisions = endless debates \u{1F480}'],
      sync:      ['Ito pinaka-critical \u{1F620} Pag nag-break to... GG \u{1F62C}','WebSocket magic ang ginawa nila dito \u{1F52E}','Real-time = real stress \u{1F62D}'],
      uiux:      ['Dito nagkanda-ubos ang braincells \u{1F62D}','Revision number 47 yata ito \u{1F605}','Design is never done, they said \u{1F612}'],
      backend:   ['PHP warriors \u{1F4AA} Hindi sila nagquit kahit kelan','MySQL queries na gumawa ng sakit ng ulo \u{1F620}','Pero ayos, gumagana naman \u{1F60E}'],
      qa:        ['Sila ang naghahanap ng bugs... at nakakahanap \u{1F62C}','Break everything to fix everything \u{1F602}','Silent heroes ng lahat ng software \u{1F3C5}'],
      analytics: ["Data don't lie... pero minsan mabagal mag-load \u{1F605}",'Charts, graphs, at sobrang daming numbers \u{1F4CA}','Kung may problema sa stats, tanungin ito \u{1F60E}'],
    };

    var AB_STORIES = [
      {
        title:'\u{1F3C0} Basketball: Born from Boredom',
        lines:[
          'Alam mo ba... yung basketball, naimbento lang dahil bored sila? \u{1F62D}',
          'Si Dr. James Naismith, isang Canadian, ginawa niya ito noong 1891.',
          'Yung original na basket? Literal na peach basket. Hindi nagbubukas sa ilalim. \u{1F602}',
          'Ibig sabihin... pagkatapos ng bawat score, may kumuha ng bola sa loob. Manually. \u{1F62D}',
          'Tapos ayun... naging global sport. Grabe yung glow up \u{1F633}\u26A1',
        ],
      },
      {
        title:'\u{1F3D0} Volleyball: Chill Sport na Hindi Pala Chill',
        lines:[
          'Iniimbento ni William G. Morgan noong 1895 para sa mga mas matanda. \u{1F605}',
          'Sabi niya, basketball daw ay "too intense".',
          'Kaya gumawa siya ng sport na may net at hindi pwedeng hawakan yung bola.',
          'Tapos ngayon? Pro volleyball players nag-eetrain ng 8 hours a day. \u{1F620}',
          'Chill sport daw. Chill. \u{1F612}',
        ],
      },
      {
        title:'\u26A1 The Dream Team: Sports Changed Forever',
        lines:[
          'Noong 1992 Barcelona Olympics, NBA players pinahintulutan na lumaro.',
          'Nag-assemble yung "Dream Team": Jordan, Magic, Bird, Barkley...',
          'Average na panalo nila: 43.8 points. AVERAGE. \u{1F633}',
          'Yung opponent nila sa first game? Puerto Rico. Score: 116-48. \u{1F480}',
          'Yung larong ito ang nagpalit ng basketball globally forever. \u{1F30D}\u26A1',
        ],
      },
      {
        title:'\u{1F3D3} Table Tennis: Olympic in Pajamas',
        lines:[
          'Table tennis nagsimula bilang after-dinner game ng mga British aristocrats noong 1880s.',
          'Ginamit nila yung mga libro bilang net. Mga cork bilang bola. \u{1F602}',
          'Tapos naging Olympics sport siya noong 1988.',
          'Ang China? Nanalo ng 28 out of 32 gold medals sa Olympics. 28. \u{1F620}',
          'From pajama game hanggang Olympic domination. Respek. \u{1F3C5}',
        ],
      },
      {
        title:'\u{1F3AF} Darts: Pub Game Goes Pro',
        lines:[
          'Darts! Ang sport na paborito ng lahat ng may beer sa kamay.',
          'Nagsimula siya sa medieval England, soldiers nagtatapon ng arrows sa wine barrels.',
          'Noong 1908 isang court case ang nagpasya na darts is a "game of skill, not chance".',
          'Kung natalo siya sa court? Illegal na sana ang darts sa UK. \u{1F631}',
          'Ngayon may pro players na kumikita ng millions. From pub to paychecks. \u{1F4B0}\u{1F60E}',
        ],
      },
    ];

    var AB_BOT_REACTIONS = [
      'ANU BA \u{1F62D}',
      'Click mo pa, di pa tapos kwento ko \u{1F620}',
      'WAIT LANG, may plot twist pa \u{1F440}',
      'Sige na nga... next na \u{1F612}',
      'Ay grabe ka \u{1F62D} tuloy mo lang',
    ];

    /* ── State ── */
    var abIsOpen     = false;
    var abCurrentTab = 'about';
    var abStoryIdx   = 0;
    var abQueue      = [];
    var abProcessing = false;

    /* ── DOM refs ── */
    var abOverlay, abChat, abAvatarFace;

    /* ── Utils ── */
    function abRand(arr){ return arr[Math.floor(Math.random()*arr.length)]; }
    function abRandInt(a,b){ return Math.floor(Math.random()*(b-a+1))+a; }
    function abMd(t){
      return (t||'')
        .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,'<em>$1</em>');
    }
    function abScroll(){ if(abChat) abChat.scrollTop=abChat.scrollHeight; }

    /* ── Queue ── */
    function abEnqueue(fn,delay){
      abQueue.push({fn:fn,delay:delay||0});
      if(!abProcessing) abDrain();
    }
    function abDrain(){
      if(!abQueue.length){ abProcessing=false; return; }
      abProcessing=true;
      var item=abQueue.shift();
      setTimeout(function(){ item.fn(); abDrain(); },item.delay);
    }

    /* ── Render helpers ── */
    function abBotMsg(html,extraClass){
      var w=document.createElement('div'); w.className='ab-msg ab-msg-bot';
      var b=document.createElement('div'); b.className='ab-bubble'+(extraClass?' '+extraClass:'');
      b.innerHTML=abMd(html); w.appendChild(b);
      abChat.appendChild(w); abScroll(); return w;
    }
    function abUserMsg(text){
      var w=document.createElement('div'); w.className='ab-msg ab-msg-user';
      var b=document.createElement('div'); b.className='ab-bubble';
      b.textContent=text; w.appendChild(b);
      abChat.appendChild(w); abScroll();
    }
    function abReaction(emoji){
      var r=document.createElement('div');
      r.className='ab-reaction ab-msg'; r.textContent=emoji;
      abChat.appendChild(r); abScroll();
    }

    /* ── Queue wrappers ── */
    function abQ(html,delay,withTyping,extraClass){
      delay=delay||300;
      var typeDur=withTyping?abRandInt(500,900):0;
      abEnqueue(function(){
        if(withTyping){
          var d=document.createElement('div');
          d.className='ab-typing'; d.id='ab-typing-dots';
          d.innerHTML='<span></span><span></span><span></span>';
          abChat.appendChild(d); abScroll();
        }
      },delay);
      if(withTyping){
        abEnqueue(function(){
          var old=document.getElementById('ab-typing-dots');
          if(old)old.remove();
          abBotMsg(html,extraClass);
        },typeDur);
      } else {
        abEnqueue(function(){ abBotMsg(html,extraClass); },0);
      }
    }
    function abQAction(fn,delay){ abEnqueue(fn,delay||350); }

    /* ── Avatar ── */
    function abSetAvatar(e){ if(abAvatarFace)abAvatarFace.textContent=e; }
    function abAvatarReact(e,ms){ abSetAvatar(e); setTimeout(function(){ abSetAvatar('\u26A1'); },ms||2000); }

    /* ── Choices ── */
    function abAddChoices(items,onPick){
      var w=document.createElement('div'); w.className='ab-choices ab-msg';
      items.forEach(function(item){
        var b=document.createElement('button'); b.className='ab-choice';
        b.textContent=item.label;
        b.addEventListener('click',function(){
          w.querySelectorAll('.ab-choice').forEach(function(x){ x.classList.add('ab-used'); });
          abUserMsg(item.label);
          onPick(item);
        });
        w.appendChild(b);
      });
      abChat.appendChild(w); abScroll();
    }

    function abAddCrewGrid(){
      var g=document.createElement('div'); g.className='ab-crew-grid ab-msg';
      AB_CREW.forEach(function(m,i){
        var c=document.createElement('div'); c.className='ab-crew-card';
        c.style.animationDelay=(i*55)+'ms';
        c.innerHTML='<div class="ab-crew-avatar">'+m.emoji+'</div>'+
          '<div><div class="ab-crew-name">'+m.name+'</div>'+
          '<div class="ab-crew-role">'+m.role+'</div></div>';
        c.addEventListener('click',function(){
          abUserMsg(m.name);
          abQ(abRand(AB_CREW_REACTIONS),200,true);
        });
        g.appendChild(c);
      });
      abChat.appendChild(g); abScroll();
    }

    function abAddContribs(){
      var l=document.createElement('div'); l.className='ab-contrib-list ab-msg';
      AB_CONTRIBUTIONS.forEach(function(item){
        var row=document.createElement('div'); row.className='ab-contrib-item';
        row.innerHTML='<span class="ab-contrib-icon">'+item.icon+'</span>'+
          '<span class="ab-contrib-label">'+item.label+'</span>'+
          '<span class="ab-contrib-arrow">\u2192</span>';
        row.addEventListener('click',function(){ abHandleContrib(item); });
        l.appendChild(row);
      });
      abChat.appendChild(l); abScroll();
    }

    /* ── Clear ── */
    function abClear(){
      if(abChat) abChat.innerHTML='';
      abQueue=[]; abProcessing=false;
    }

    /* ── Tab system ── */
    function abActivateTab(key){
      abCurrentTab=key;
      document.querySelectorAll('.ab-tab').forEach(function(t){
        t.classList.toggle('ab-tab-active',t.dataset.tab===key);
      });
      abClear();
      switch(key){
        case 'about':   abStartAbout();   break;
        case 'crew':    abStartCrew();    break;
        case 'contrib': abStartContrib(); break;
        case 'story':   abStartStory();   break;
      }
    }

    /* ── ABOUT flow ── */
    function abStartAbout(){
      abSetAvatar('\u{1F60F}');
      abQ('HOY! Curious ka about SportsSync? \u{1F60F}',0,true);
      abQ('Sige, kwento ko sa iyo... \u{1F447}',300,true);
      abQ('So, ano ba talaga ang <strong>SportsSync</strong>? \u{1F914}',400,true);
      abQAction(function(){
        abAddChoices([
          {label:'\u{1F914} Ano siya exactly?', id:'what'},
          {label:'\u26A1 Ano ang kaya niya?',   id:'can'},
          {label:'\u{1F465} Para kanino ito?',  id:'who'},
          {label:'\u{1F527} Paano gumagana?',   id:'how'},
        ],abHandleAbout);
      },500);
    }

    function abHandleAbout(item){
      abAvatarReact('\u{1F914}',1500);
      if(item.id==='what'){
        abQ('OK so...',200,true);
        abQ('SportsSync ay isang <strong>real-time sports management system</strong>. \u{1F3C6}',300,true);
        abQ('Lahat ng scores, players, at results? <strong>Dito lahat.</strong> \u26A1',400,true);
        abQ('Real-time siya. Ibig sabihin, walang F5. Automatic mag-update. \u{1F60E}',300,true);
      } else if(item.id==='can'){
        abQ('Kaya niya ang marami, boss. \u{1F620}',200,true);
        abQ('\u{1F3C0} Live scoring, basketball, volleyball, badminton, table tennis, darts',300,true);
        abQ('\u{1F4CA} Analytics, player stats, match history, performance trends',300,true);
        abQ('\u{1F4CB} Match reports, auto-generated pagkatapos ng laro',300,true);
        abQ('At lahat? <strong>Real-time. Walang manual refresh.</strong> \u{1F60F}',400,true);
      } else if(item.id==='who'){
        abQ('Para sa tatlong uri ng tao: \u{1F447}',200,true);
        abQ('\u{1F511} <strong>Admins</strong>, sila ang nagco-control ng lahat.',300,true);
        abQ('\u{1F4FA} <strong>Viewers</strong>, nakaka-watch ng live scores kahit wala sa venue.',300,true);
        abQ('\u{1F3C5} <strong>Scorekeepers</strong>, sila ang naglalagay ng scores sa live game.',300,true);
        abQ('Tig-isa silang role, pero lahat connected sa iisang system. SSOT. \u{1F60E}',400,true);
      } else {
        abQ('Medyo technical pero kaya mo yan \u{1F606}',200,true);
        abQ('Step 1: Admin nag-eenter ng score sa live UI. \u270D',300,true);
        abQ('Step 2: System mag-save agad, <strong>Single Source of Truth (SSOT)</strong>. \u{1F5C4}',300,true);
        abQ('Step 3: Lahat ng connected viewers? <strong>Automatic nang nag-update.</strong> \u{1F504}',300,true);
        abQ('Walang manual sync. Walang delay. Grabe diba? \u{1F60F}',400,true);
      }
      abQAction(function(){
        abAddChoices([
          {label:'\u{1F465} Meet the Crew',      id:'crew'},
          {label:'\u{1F527} See Contributions',  id:'contrib'},
          {label:'\u{1F4D6} Tell me a story',    id:'story'},
          {label:'\u{1F501} Ask something else', id:'more'},
        ],function(c){
          if(c.id==='more'){ abClear(); abStartAbout(); return; }
          abActivateTab(c.id);
        });
      },500);
    }

    /* ── CREW flow ── */
    function abStartCrew(){
      abSetAvatar('\u{1F525}');
      abQ('Sige, ipakikilala ko sila sa iyo! \u{1F620}',0,true);
      abQ('\u{1F525} <strong>Behind this system... Built by:</strong>',300,true);
      abQAction(function(){
        abAddCrewGrid();
        abReaction('\u{1F4AA}');
      },400);
      abQ('I-click ang pangalan para makita ang reaction! \u{1F60F}',500,true);
      abQ(abRand(AB_CREW_REACTIONS),400,true);
      abQAction(function(){
        abAddChoices([
          {label:'\u{1F527} Sino gumawa ng ano?', id:'contrib'},
          {label:'\u{1F4D6} May kwento pa?',       id:'story'},
          {label:'\u2190 Balik sa About',           id:'about'},
        ],function(c){ abActivateTab(c.id==='contrib'?'contrib':c.id==='story'?'story':'about'); });
      },500);
    }

    /* ── CONTRIB flow ── */
    function abStartContrib(){
      abSetAvatar('\u{1F6E0}');
      abQ('Eto ang mga major parts ng SportsSync! \u{1F6E0}',0,true);
      abQ('I-click ang bawat item para malaman ang <em>tea</em> nila \u{1F60F}',300,true);
      abQAction(function(){ abAddContribs(); },400);
    }

    function abHandleContrib(item){
      abAvatarReact('\u{1F92F}',2000);
      var comments=AB_CONTRIB_COMMENTS[item.key]||['Grabe to \u{1F620}'];
      abQ(item.icon+' <strong>'+item.label+'</strong>',200,true,'ab-bubble-highlight');
      abQ(abRand(comments),300,true);
      abQAction(function(){
        abAddChoices([
          {label:'\u{1F50D} Tingnan ang isa pa', id:'more'},
          {label:'\u{1F465} Meet the Crew',       id:'crew'},
          {label:'\u{1F4D6} Tell me a story',     id:'story'},
        ],function(c){
          if(c.id==='more'){
            abQ('Sige, pili ulit! \u{1F60F}',200,true);
            abQAction(function(){ abAddContribs(); },400);
          } else { abActivateTab(c.id==='crew'?'crew':'story'); }
        });
      },400);
    }

    /* ── STORY flow ── */
    function abStartStory(){
      abSetAvatar('\u{1F4D6}');
      abStoryIdx=abRandInt(0,AB_STORIES.length-1);
      abQ('Ohhh story time! \u{1F4D6} Sit down, makinig ka muna! \u{1F620}',0,true);
      abQ(abRand(AB_BOT_REACTIONS),300,true);
      abQAction(function(){ abPlayStory(abStoryIdx); },500);
    }

    function abPlayStory(idx){
      var story=AB_STORIES[idx];
      abQAction(function(){
        var card=document.createElement('div');
        card.className='ab-story-card ab-msg';
        var titleEl=document.createElement('div');
        titleEl.className='ab-story-title'; titleEl.textContent=story.title;
        var bodyEl=document.createElement('div');
        bodyEl.className='ab-story-body'; bodyEl.id='ab-story-body-'+idx;
        card.appendChild(titleEl); card.appendChild(bodyEl);
        abChat.appendChild(card); abScroll();
        abDripLines(story.lines,bodyEl,idx);
      },200);
    }

    function abDripLines(lines,container,idx){
      var i=0;
      function next(){
        if(i>=lines.length){
          abQAction(function(){
            abReaction('\u{1F633}');
            abAddChoices([
              {label:'\u27A1 Next story',      id:'next'},
              {label:'\u{1F3B2} Random story', id:'random'},
              {label:'\u2190 Back to About',   id:'about'},
            ],function(c){
              abClear();
              if(c.id==='about'){ abActivateTab('about'); return; }
              abStoryIdx=(c.id==='next')?(abStoryIdx+1)%AB_STORIES.length:abRandInt(0,AB_STORIES.length-1);
              abStartStory();
            });
          },600);
          return;
        }
        var line=lines[i]; i++;
        var delay=600+Math.min(line.length*11,1100);
        abEnqueue(function(){
          var dot=document.createElement('span');
          dot.textContent=' \u23F3'; dot.style.display='inline-block';
          container.appendChild(dot); abScroll();
          setTimeout(function(){
            dot.remove();
            var p=document.createElement('p');
            p.style.margin='4px 0'; p.style.lineHeight='1.6';
            p.innerHTML=abMd(line);
            container.appendChild(p); abScroll();
            next();
          },delay);
        },300);
      }
      next();
    }

    /* ── Open / Close ── */
    function abOpen(){
      if(abIsOpen)return;
      abIsOpen=true;
      abOverlay.classList.add('ab-visible');
      if(abChat.children.length===0) abActivateTab('about');
    }
    function abClose(){
      if(!abIsOpen)return;
      abIsOpen=false;
      abOverlay.classList.remove('ab-visible');
    }

    /* ── Init ── */
    function abInit(){
      abOverlay  = document.getElementById('ab-overlay');
      abChat     = document.getElementById('ab-chat');
      abAvatarFace = document.getElementById('ab-avatar-face-modal');

      if(!abOverlay) return; // About modal not on this page

      var openBtn=document.getElementById('ab-open-btn');
      if(openBtn) openBtn.addEventListener('click',abOpen);

      var closeBtn=document.getElementById('ab-close-modal');
      if(closeBtn) closeBtn.addEventListener('click',abClose);

      abOverlay.addEventListener('click',function(e){
        if(e.target===abOverlay) abClose();
      });

      document.querySelectorAll('.ab-tab').forEach(function(tab){
        tab.addEventListener('click',function(){ abActivateTab(tab.dataset.tab); });
      });

      // Footer quick buttons
      var btnStory   = document.getElementById('ab-footer-story');
      var btnCrew    = document.getElementById('ab-footer-crew');
      var btnContrib = document.getElementById('ab-footer-contrib');
      if(btnStory)   btnStory.addEventListener('click',  function(){ abActivateTab('story');   });
      if(btnCrew)    btnCrew.addEventListener('click',   function(){ abActivateTab('crew');    });
      if(btnContrib) btnContrib.addEventListener('click',function(){ abActivateTab('contrib'); });

      document.addEventListener('keydown',function(e){
        if(e.key==='Escape'&&abIsOpen) abClose();
      });
    }

    if(document.readyState==='loading'){
      document.addEventListener('DOMContentLoaded',abInit);
    } else {
      abInit();
    }

  })(); /* end About Bot IIFE */


})();