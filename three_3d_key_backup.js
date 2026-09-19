// ============================================================
// THREE_3D_KEY_BACKUP.JS
// Backup of HoQuoc 3D Key / Cyber Engine (Three.js WebGL)
// Extracted from index.php before removal per user instruction
// ============================================================

// CDN dependencies needed if re-enabled:
// <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" defer></script>

(function(){
    function isWebGLAvailable() {
        try {
            var c = document.createElement('canvas');
            return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
        } catch(e) {
            return false;
        }
    }

    function init3DServerIntro() {
        var overlay = document.getElementById('server3dIntro');
        var canvas = document.getElementById('server3dCanvas');
        var wrap = document.getElementById('server3dCanvasWrap');
        var enterBtn = document.getElementById('server3dEnterBtn');
        var skipBtn = document.getElementById('server3dSkipBtn');
        var replayBtn = document.getElementById('btnReplay3DServer');
        var progressBar = document.getElementById('server3dProgressFill');
        var line2 = document.getElementById('termLine2');
        var line3 = document.getElementById('termLine3');
        var line4 = document.getElementById('termLine4');

        if (!overlay || !canvas || !wrap) return;

        if (!isWebGLAvailable() || typeof THREE === 'undefined') {
            overlay.classList.add('dismissed');
            return;
        }

        var isMobile = window.innerWidth <= 640;
        var isRunning = true;
        var isWarping = false;

        // Scene & Camera
        var scene = new THREE.Scene();
        var width = window.innerWidth;
        var height = window.innerHeight;
        var defaultFOV = isMobile ? 48 : 40;
        var defaultCamZ = isMobile ? 8.4 : 7.0;
        var camera = new THREE.PerspectiveCamera(defaultFOV, width / height, 0.1, 100);
        camera.position.set(0, 0.35, defaultCamZ);
        camera.lookAt(0, 0, 0);

        var renderer = new THREE.WebGLRenderer({
            canvas: canvas,
            alpha: true,
            antialias: true,
            powerPreference: 'high-performance'
        });
        renderer.setSize(width, height);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, isMobile ? 1.5 : 2));
        if (THREE.ACESFilmicToneMapping) {
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.35;
        }

        // Architectural Metallic Studio Lighting (Refined, Monochromatic Luxury)
        var ambient = new THREE.AmbientLight(0xffffff, 1.25);
        scene.add(ambient);

        var keyLight = new THREE.DirectionalLight(0xffffff, 2.8);
        keyLight.position.set(5, 8, 6);
        scene.add(keyLight);

        var slateFill = new THREE.PointLight(0x94a3b8, 1.8, 25);
        slateFill.position.set(-5, -2, 4);
        scene.add(slateFill);

        var rimLight = new THREE.PointLight(0xe2e8f0, 2.2, 20);
        rimLight.position.set(4, -4, -3);
        scene.add(rimLight);

        var coreGlowLight = new THREE.PointLight(0x38bdf8, 1.2, 10);
        coreGlowLight.position.set(0, 0, 0);
        scene.add(coreGlowLight);

        // Procedural soft-glowing sprite textures
        function createParticleTexture(coreColor, edgeColor) {
            var c = document.createElement('canvas');
            c.width = 64;
            c.height = 64;
            var ctx = c.getContext('2d');
            var grad = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
            grad.addColorStop(0, coreColor || '#ffffff');
            grad.addColorStop(0.25, edgeColor || 'rgba(226, 232, 240, 0.6)');
            grad.addColorStop(0.65, 'rgba(148, 163, 184, 0.15)');
            grad.addColorStop(1, 'rgba(0, 0, 0, 0)');
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(32, 32, 31, 0, Math.PI * 2);
            ctx.fill();
            var tex = new THREE.CanvasTexture(c);
            tex.needsUpdate = true;
            return tex;
        }

        var stardustTex = createParticleTexture('#ffffff', 'rgba(226, 232, 240, 0.55)');
        var subtleOrbitTex = createParticleTexture('#f8fafc', 'rgba(148, 163, 184, 0.3)');

        // Ambient stardust
        var pCount = isMobile ? 120 : 180;
        var pGeo = new THREE.BufferGeometry();
        var pPos = new Float32Array(pCount * 3);
        var pData = [];

        for (var p = 0; p < pCount; p++) {
            var x0 = (Math.random() - 0.5) * 14;
            var y0 = (Math.random() - 0.5) * 10;
            var z0 = (Math.random() - 0.5) * 12;
            pPos[p * 3] = x0;
            pPos[p * 3 + 1] = y0;
            pPos[p * 3 + 2] = z0;
            pData.push({
                x0: x0, y0: y0, z0: z0,
                ax: 0.15 + Math.random() * 0.4,
                ay: 0.2 + Math.random() * 0.5,
                az: 0.15 + Math.random() * 0.4,
                fx: 0.2 + Math.random() * 0.4,
                fy: 0.15 + Math.random() * 0.3,
                fz: 0.2 + Math.random() * 0.4,
                px: Math.random() * Math.PI * 2,
                py: Math.random() * Math.PI * 2,
                pz: Math.random() * Math.PI * 2
            });
        }
        pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
        var pMat = new THREE.PointsMaterial({
            size: isMobile ? 0.16 : 0.22,
            map: stardustTex,
            transparent: true,
            opacity: 0.55,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });
        var cosmicDust = new THREE.Points(pGeo, pMat);
        scene.add(cosmicDust);

        // Orbital sparks
        var vCount = isMobile ? 40 : 70;
        var vGeo = new THREE.BufferGeometry();
        var vPos = new Float32Array(vCount * 3);
        var vData = [];

        for (var v = 0; v < vCount; v++) {
            var vRad = 1.4 + Math.random() * 2.6;
            var vAng = Math.random() * Math.PI * 2;
            var vY0 = (Math.random() - 0.5) * 4.0;
            vPos[v * 3] = Math.cos(vAng) * vRad;
            vPos[v * 3 + 1] = vY0;
            vPos[v * 3 + 2] = Math.sin(vAng) * vRad;
            vData.push({
                r: vRad,
                ang: vAng,
                y0: vY0,
                speed: (0.35 + Math.random() * 0.5) * (Math.random() > 0.5 ? 1 : -1),
                wobble: 0.15 + Math.random() * 0.3,
                phase: Math.random() * Math.PI * 2
            });
        }
        vGeo.setAttribute('position', new THREE.BufferAttribute(vPos, 3));
        var vMat = new THREE.PointsMaterial({
            size: isMobile ? 0.15 : 0.2,
            map: subtleOrbitTex,
            transparent: true,
            opacity: 0.5,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });
        var vortexSparks = new THREE.Points(vGeo, vMat);
        scene.add(vortexSparks);

        // Floor Grid
        var floorGrid = new THREE.GridHelper(32, 24, 0x334155, 0x0f172a);
        floorGrid.position.y = -3.2;
        scene.add(floorGrid);

        // Master Rig
        var mainRig = new THREE.Group();
        mainRig.rotation.set(0.12, 0.22, 0);
        scene.add(mainRig);

        var platinumChromeMat = new THREE.MeshStandardMaterial({
            color: 0xf8fafc,
            metalness: 0.98,
            roughness: 0.1,
            flatShading: false
        });
        var brushedTitaniumMat = new THREE.MeshStandardMaterial({
            color: 0x94a3b8,
            metalness: 0.92,
            roughness: 0.22
        });
        var darkObsidianMat = new THREE.MeshStandardMaterial({
            color: 0x0f172a,
            metalness: 0.88,
            roughness: 0.28
        });
        var gemMat = new THREE.MeshStandardMaterial({
            color: 0x0f172a,
            emissive: 0x38bdf8,
            emissiveIntensity: 0.22,
            metalness: 0.92,
            roughness: 0.08,
            flatShading: true
        });
        var silverAccentMat = new THREE.MeshBasicMaterial({ color: 0xe2e8f0 });

        // MODEL 1: 3D TITANIUM CYBER KEY
        var keyGroup = new THREE.Group();
        mainRig.add(keyGroup);

        var keyAssembly = new THREE.Group();
        keyAssembly.rotation.z = -0.28;
        keyAssembly.rotation.x = 0.15;
        keyAssembly.position.y = 0.45;
        keyGroup.add(keyAssembly);

        var bowGroup = new THREE.Group();
        keyAssembly.add(bowGroup);

        var bowOuter = new THREE.Mesh(new THREE.TorusGeometry(1.08, 0.12, 16, 6), platinumChromeMat);
        bowGroup.add(bowOuter);

        var bowInner = new THREE.Mesh(new THREE.TorusGeometry(0.88, 0.035, 12, 6), brushedTitaniumMat);
        bowGroup.add(bowInner);

        var bowRing = new THREE.Mesh(new THREE.TorusGeometry(0.68, 0.02, 16, 36), silverAccentMat);
        bowGroup.add(bowRing);

        var gemGeo = new THREE.OctahedronGeometry(0.46, 0);
        var gem = new THREE.Mesh(gemGeo, gemMat);
        bowGroup.add(gem);

        var gemRing = new THREE.Mesh(new THREE.TorusGeometry(0.55, 0.016, 12, 32), platinumChromeMat);
        bowGroup.add(gemRing);

        var shaftGroup = new THREE.Group();
        keyAssembly.add(shaftGroup);

        var shaftCore = new THREE.Mesh(new THREE.CylinderGeometry(0.13, 0.11, 3.4, 18), darkObsidianMat);
        shaftCore.position.y = -1.9;
        shaftGroup.add(shaftCore);

        var conduit1 = new THREE.Mesh(new THREE.CylinderGeometry(0.018, 0.018, 3.3, 8), silverAccentMat);
        conduit1.position.set(0.14, -1.9, 0);
        shaftGroup.add(conduit1);

        var conduit2 = new THREE.Mesh(new THREE.CylinderGeometry(0.018, 0.018, 3.3, 8), silverAccentMat);
        conduit2.position.set(-0.14, -1.9, 0);
        shaftGroup.add(conduit2);

        for (var c = 0; c < 4; c++) {
            var rib = new THREE.Mesh(new THREE.TorusGeometry(0.16, 0.028, 12, 20), platinumChromeMat);
            rib.rotation.x = Math.PI / 2;
            rib.position.y = -0.7 - c * 0.7;
            shaftGroup.add(rib);
        }

        var bitGroup = new THREE.Group();
        bitGroup.position.set(0.26, -2.85, 0);
        shaftGroup.add(bitGroup);

        var bitMain = new THREE.Mesh(new THREE.BoxGeometry(0.52, 0.82, 0.12), platinumChromeMat);
        bitGroup.add(bitMain);

        var tooth1 = new THREE.Mesh(new THREE.BoxGeometry(0.36, 0.15, 0.15), brushedTitaniumMat);
        tooth1.position.set(0.24, 0.24, 0);
        bitGroup.add(tooth1);

        var tooth2 = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.15, 0.15), brushedTitaniumMat);
        tooth2.position.set(0.18, -0.06, 0);
        bitGroup.add(tooth2);

        var tooth3 = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.15, 0.15), brushedTitaniumMat);
        tooth3.position.set(0.28, -0.32, 0);
        bitGroup.add(tooth3);

        var toothGlow1 = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.14, 0.16), silverAccentMat);
        toothGlow1.position.set(0.42, 0.24, 0);
        bitGroup.add(toothGlow1);

        var toothGlow2 = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.14, 0.16), silverAccentMat);
        toothGlow2.position.set(0.29, -0.06, 0);
        bitGroup.add(toothGlow2);

        var toothGlow3 = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.14, 0.16), silverAccentMat);
        toothGlow3.position.set(0.49, -0.32, 0);
        bitGroup.add(toothGlow3);

        var keyRing1 = new THREE.Mesh(new THREE.TorusGeometry(2.1, 0.016, 16, 90), brushedTitaniumMat);
        var keyRing2 = new THREE.Mesh(new THREE.TorusGeometry(2.65, 0.018, 16, 90), silverAccentMat);
        keyRing1.rotation.x = Math.PI / 3.5;
        keyRing2.rotation.y = Math.PI / 3;
        keyGroup.add(keyRing1);
        keyGroup.add(keyRing2);

        var satGeo = new THREE.OctahedronGeometry(0.09, 0);
        var keySat1 = new THREE.Mesh(satGeo, platinumChromeMat);
        keySat1.position.x = 2.1;
        keyRing1.add(keySat1);

        var keySat2 = new THREE.Mesh(satGeo, silverAccentMat);
        keySat2.position.y = 2.65;
        keyRing2.add(keySat2);

        var keyPulseRing = new THREE.Mesh(new THREE.RingGeometry(0.15, 1.25, 32), new THREE.MeshBasicMaterial({
            color: 0x94a3b8,
            side: THREE.DoubleSide,
            transparent: true,
            opacity: 0.15,
            blending: THREE.AdditiveBlending
        }));
        keyPulseRing.rotation.x = Math.PI / 2;
        keyAssembly.add(keyPulseRing);

        // MODEL 2: STARGATE PORTAL
        var portalGroup = new THREE.Group();
        portalGroup.visible = false;
        mainRig.add(portalGroup);

        var stargateOuter = new THREE.Mesh(new THREE.TorusGeometry(2.45, 0.16, 18, 48), platinumChromeMat);
        portalGroup.add(stargateOuter);

        var glyphTrack = new THREE.Mesh(new THREE.TorusGeometry(2.1, 0.045, 16, 48), silverAccentMat);
        portalGroup.add(glyphTrack);

        for (var ch = 0; ch < 8; ch++) {
            var chAngle = (ch / 8) * Math.PI * 2;
            var chClamp = new THREE.Group();
            var clampBox = new THREE.Mesh(new THREE.BoxGeometry(0.28, 0.46, 0.32), brushedTitaniumMat);
            chClamp.add(clampBox);
            var clampGlow = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.1, 0.34), silverAccentMat);
            clampGlow.position.y = 0.18;
            chClamp.add(clampGlow);
            chClamp.position.set(Math.cos(chAngle) * 2.45, Math.sin(chAngle) * 2.45, 0);
            chClamp.rotation.z = chAngle + Math.PI / 2;
            portalGroup.add(chClamp);
        }

        var vortexMat1 = new THREE.MeshBasicMaterial({
            color: 0x38bdf8,
            side: THREE.DoubleSide,
            transparent: true,
            opacity: 0.18,
            blending: THREE.AdditiveBlending
        });
        var vortexMat2 = new THREE.MeshBasicMaterial({
            color: 0xe2e8f0,
            side: THREE.DoubleSide,
            transparent: true,
            opacity: 0.15,
            blending: THREE.AdditiveBlending
        });
        var vortexDisc1 = new THREE.Mesh(new THREE.RingGeometry(0.12, 1.95, 36), vortexMat1);
        var vortexDisc2 = new THREE.Mesh(new THREE.RingGeometry(0.12, 1.7, 36), vortexMat2);
        portalGroup.add(vortexDisc1);
        portalGroup.add(vortexDisc2);

        var singularity = new THREE.Mesh(new THREE.SphereGeometry(0.48, 28, 28), new THREE.MeshBasicMaterial({ color: 0xffffff }));
        portalGroup.add(singularity);

        // MODEL 3: QUANTUM CORE
        var coreGroup = new THREE.Group();
        coreGroup.visible = false;
        mainRig.add(coreGroup);

        var plasmaHeart = new THREE.Mesh(new THREE.SphereGeometry(0.55, 28, 28), new THREE.MeshBasicMaterial({ color: 0xffffff }));
        coreGroup.add(plasmaHeart);

        var coreDiamond = new THREE.Mesh(new THREE.IcosahedronGeometry(1.02, 0), gemMat);
        coreGroup.add(coreDiamond);

        var coreRingX = new THREE.Mesh(new THREE.TorusGeometry(1.5, 0.02, 16, 64), silverAccentMat);
        var coreRingY = new THREE.Mesh(new THREE.TorusGeometry(1.9, 0.022, 16, 64), brushedTitaniumMat);
        var coreRingZ = new THREE.Mesh(new THREE.TorusGeometry(2.35, 0.024, 16, 64), platinumChromeMat);
        coreGroup.add(coreRingX);
        coreGroup.add(coreRingY);
        coreGroup.add(coreRingZ);

        var coreCage = new THREE.Mesh(new THREE.DodecahedronGeometry(2.1, 0), new THREE.MeshBasicMaterial({
            color: 0x475569,
            wireframe: true,
            transparent: true,
            opacity: 0.35
        }));
        coreGroup.add(coreCage);

        var coreSatellites = [];
        for (var cs = 0; cs < 6; cs++) {
            var cSatMesh = new THREE.Mesh(new THREE.OctahedronGeometry(0.12, 0), platinumChromeMat);
            coreGroup.add(cSatMesh);
            coreSatellites.push({ mesh: cSatMesh, rad: 2.7, speed: 0.7 + cs * 0.15, phase: (cs / 6) * Math.PI * 2 });
        }

        // Model Switcher
        var currentMode = 'key';
        try {
            var savedMode = localStorage.getItem('hoquoc_3d_mode');
            if (savedMode && (savedMode === 'key' || savedMode === 'portal' || savedMode === 'core')) {
                currentMode = savedMode;
            }
        } catch(e) {}

        var badgeText = document.getElementById('server3dBadgeText');
        var initText = document.getElementById('server3dInitText');
        var modeBtns = document.querySelectorAll('.server3d-mode-btn');

        function set3DMode(mode, save) {
            currentMode = mode;
            if (save) {
                try { localStorage.setItem('hoquoc_3d_mode', mode); } catch(e) {}
            }

            keyGroup.visible = (mode === 'key');
            portalGroup.visible = (mode === 'portal');
            coreGroup.visible = (mode === 'core');

            if (mode === 'key') {
                keyGroup.scale.set(0.85, 0.85, 0.85);
                if (window.gsap) gsap.to(keyGroup.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
                if (badgeText) badgeText.textContent = '3D TITANIUM KEY // HOQUOC AUTH';
                if (initText) initText.textContent = 'Khởi tạo HoQuoc Titanium Key System...';
            } else if (mode === 'portal') {
                portalGroup.scale.set(0.85, 0.85, 0.85);
                if (window.gsap) gsap.to(portalGroup.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
                if (badgeText) badgeText.textContent = '3D STARGATE // HOQUOC GATEWAY';
                if (initText) initText.textContent = 'Kết nối cổng không gian HoQuoc Stargate...';
            } else if (mode === 'core') {
                coreGroup.scale.set(0.85, 0.85, 0.85);
                if (window.gsap) gsap.to(coreGroup.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
                if (badgeText) badgeText.textContent = '3D QUANTUM CORE // HOQUOC ENCRYPTION';
                if (initText) initText.textContent = 'Khởi tạo lõi lượng tử HoQuoc Quantum Core...';
            }

            modeBtns.forEach(function(btn){
                btn.classList.toggle('active', btn.dataset.mode === mode);
            });
        }

        modeBtns.forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                var m = btn.dataset.mode;
                if (m) set3DMode(m, true);
            });
        });

        set3DMode(currentMode, false);

        // Interaction
        var mouse = { x: 0, y: 0 };
        var isDragging = false;
        var prevPointer = { x: 0, y: 0 };
        var rotVelY = 0;
        var rotVelX = 0;
        var time = 0;

        wrap.addEventListener('pointerdown', function(e){
            isDragging = true;
            prevPointer.x = e.clientX;
            prevPointer.y = e.clientY;
            try { wrap.setPointerCapture(e.pointerId); } catch(err) {}
        });

        window.addEventListener('pointermove', function(e){
            mouse.x = (e.clientX / window.innerWidth) * 2 - 1;
            mouse.y = -(e.clientY / window.innerHeight) * 2 + 1;

            if (isDragging) {
                var dx = e.clientX - prevPointer.x;
                var dy = e.clientY - prevPointer.y;
                prevPointer.x = e.clientX;
                prevPointer.y = e.clientY;
                rotVelY = dx * 0.007;
                rotVelX = dy * 0.007;
                mainRig.rotation.y += rotVelY;
                mainRig.rotation.x += rotVelX;
            }
        });

        window.addEventListener('pointerup', function(e){
            if (isDragging) {
                isDragging = false;
                try { wrap.releasePointerCapture(e.pointerId); } catch(err) {}
            }
        });

        var bootProgress = 0;
        var bootTimer = setInterval(function(){
            bootProgress += 3;
            if (progressBar) progressBar.style.width = Math.min(bootProgress, 100) + '%';
            if (bootProgress >= 25 && line2) line2.style.display = 'flex';
            if (bootProgress >= 55 && line3) line3.style.display = 'flex';
            if (bootProgress >= 85 && line4) line4.style.display = 'flex';

            if (bootProgress >= 100) {
                clearInterval(bootTimer);
                if (enterBtn) {
                    enterBtn.style.transform = 'scale(1.05)';
                    setTimeout(function(){ enterBtn.style.transform = ''; }, 300);
                }
            }
        }, 60);

        function enterSystem() {
            if (isWarping) return;
            isWarping = true;
            var startTime = performance.now();
            var duration = 720;
            var startZ = camera.position.z;
            var startFOV = camera.fov;

            function warpStep(now) {
                var elapsed = now - startTime;
                var progress = Math.min(elapsed / duration, 1);
                var ease = progress * progress * progress;

                camera.position.z = startZ - ease * (startZ - 0.2);
                camera.fov = startFOV + ease * 45;
                camera.updateProjectionMatrix();

                mainRig.rotation.y += ease * 0.12;
                mainRig.rotation.z += ease * 0.04;

                if (progress > 0.6) {
                    overlay.style.opacity = String(1 - (progress - 0.6) / 0.4);
                }

                if (progress < 1) {
                    requestAnimationFrame(warpStep);
                } else {
                    overlay.classList.add('dismissed');
                    overlay.style.opacity = '';
                    isRunning = false;
                    if (window.triggerPageEntrance) window.triggerPageEntrance();
                }
            }
            requestAnimationFrame(warpStep);
        }

        if (enterBtn) enterBtn.addEventListener('click', enterSystem);
        if (skipBtn) skipBtn.addEventListener('click', function(){
            overlay.classList.add('dismissed');
            isRunning = false;
            if (window.triggerPageEntrance) window.triggerPageEntrance();
        });

        if (replayBtn) {
            replayBtn.addEventListener('click', function(){
                overlay.classList.remove('dismissed');
                isWarping = false;
                isRunning = true;
                camera.position.set(0, 0.35, defaultCamZ);
                camera.fov = defaultFOV;
                camera.updateProjectionMatrix();
                mainRig.rotation.set(0.12, 0.22, 0);
                rotVelY = 0;
                rotVelX = 0;
                bootProgress = 100;
                if (progressBar) progressBar.style.width = '100%';
                if (line2) line2.style.display = 'flex';
                if (line3) line3.style.display = 'flex';
                if (line4) line4.style.display = 'flex';
                animate();
            });
        }

        function onResize() {
            width = window.innerWidth;
            height = window.innerHeight;
            isMobile = width <= 640;
            defaultFOV = isMobile ? 48 : 40;
            defaultCamZ = isMobile ? 8.4 : 7.0;
            if (!isWarping) {
                camera.fov = defaultFOV;
                camera.position.z = defaultCamZ;
            }
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            renderer.setSize(width, height);
        }
        window.addEventListener('resize', onResize);

        function animate() {
            if (!isRunning) return;
            requestAnimationFrame(animate);
            time += 0.016;

            if (!isDragging) {
                mainRig.rotation.y += rotVelY;
                rotVelY *= 0.93;
                if (Math.abs(rotVelY) < 0.0001) rotVelY = 0;

                mainRig.rotation.y += 0.006;
                var targetTiltX = mouse.y * 0.18 + 0.12;
                mainRig.rotation.x += (targetTiltX - mainRig.rotation.x) * 0.05;
            }

            if (keyGroup.visible) {
                keyAssembly.position.y = 0.45 + Math.sin(time * 2.0) * 0.14;
                keyAssembly.rotation.x = 0.15 + Math.cos(time * 1.6) * 0.04;
                gem.rotation.y = time * 1.8;
                gem.rotation.x = Math.sin(time * 1.2) * 0.4;
                gemRing.rotation.z = -time * 2.2;
                gemRing.rotation.y = time * 1.4;
                keyRing1.rotation.x += 0.012;
                keyRing1.rotation.y += 0.018;
                keyRing2.rotation.y -= 0.014;
                keyRing2.rotation.z += 0.016;
                keyPulseRing.position.y = -1.9 + Math.sin(time * 2.8) * 1.35;
                keyPulseRing.material.opacity = 0.25 + Math.cos(time * 2.8) * 0.15;
            }

            if (portalGroup.visible) {
                glyphTrack.rotation.z -= 0.012;
                vortexDisc1.rotation.z += 0.024;
                vortexDisc2.rotation.z -= 0.018;
                var sPulse = Math.sin(time * 3.5) * 0.12 + 0.95;
                singularity.scale.set(sPulse, sPulse, sPulse);
            }

            if (coreGroup.visible) {
                coreDiamond.rotation.y = time * 1.4;
                coreDiamond.rotation.x = Math.sin(time * 0.9) * 0.4;
                coreRingX.rotation.x = time * 1.1;
                coreRingY.rotation.y = -time * 1.3;
                coreRingZ.rotation.z = time * 0.9;
                coreCage.rotation.y = -time * 0.6;
                var pPulse = Math.sin(time * 4) * 0.15 + 1.0;
                plasmaHeart.scale.set(pPulse, pPulse, pPulse);

                for (var s = 0; s < coreSatellites.length; s++) {
                    var sat = coreSatellites[s];
                    var sAng = time * sat.speed + sat.phase;
                    sat.mesh.position.set(
                        Math.cos(sAng) * sat.rad,
                        Math.sin(sAng * 1.5) * 0.8,
                        Math.sin(sAng) * sat.rad
                    );
                }
            }

            var cPosArr = pGeo.attributes.position.array;
            for (var i = 0; i < pCount; i++) {
                var d = pData[i];
                cPosArr[i * 3]     = d.x0 + Math.sin(time * d.fx + d.px) * d.ax + Math.cos(time * 0.15 + d.pz) * 0.35;
                cPosArr[i * 3 + 1] = d.y0 + Math.sin(time * d.fy + d.py) * d.ay + Math.sin(time * 0.22 + d.px) * 0.28;
                cPosArr[i * 3 + 2] = d.z0 + Math.cos(time * d.fz + d.pz) * d.az + Math.sin(time * 0.18 + d.py) * 0.35;
            }
            pGeo.attributes.position.needsUpdate = true;

            var vPosArr = vGeo.attributes.position.array;
            for (var j = 0; j < vCount; j++) {
                var vd = vData[j];
                vd.ang += vd.speed * 0.018;
                var vRad = vd.r + Math.sin(time * 1.6 + vd.phase) * vd.wobble;
                vPosArr[j * 3]     = Math.cos(vd.ang) * vRad;
                vPosArr[j * 3 + 1] = vd.y0 + Math.sin(time * 2.2 + vd.phase) * 0.75;
                vPosArr[j * 3 + 2] = Math.sin(vd.ang) * vRad;
            }
            vGeo.attributes.position.needsUpdate = true;

            renderer.render(scene, camera);
        }
        animate();
    }

    function checkThree() {
        if (typeof THREE !== 'undefined') {
            init3DServerIntro();
        } else {
            var count = 0;
            var timer = setInterval(function(){
                count++;
                if (typeof THREE !== 'undefined') {
                    clearInterval(timer);
                    init3DServerIntro();
                } else if (count > 25) {
                    clearInterval(timer);
                }
            }, 120);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkThree);
    } else {
        checkThree();
    }
})();
