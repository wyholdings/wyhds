    // 서비스 카드 인터랙션 

    const cards = document.querySelectorAll('.item')
    const about = document.querySelector('.service_list')
    const isMobile = window.innerWidth <= 768

    const baseGap = isMobile ? 16 : 50
    const gap = isMobile ? 16 : 70
    const strength = isMobile ? 0.08 : 0.18   // 🔥 축소감 강화
    const moveY = isMobile ? 12 : 32

    let ticking = false

    //  카드 겹침 간격
    cards.forEach((card, index) => {
        let extraGap = index === cards.length - 1 ? (isMobile ? 40 : 80) : 0
        card.style.paddingTop =
        `${baseGap + index * gap + extraGap}px`
    })

    function update() {
        const scrollY = window.scrollY
        const windowH = window.innerHeight
        const sectionTop = about.offsetTop
        const sectionHeight = about.offsetHeight

        const start = sectionTop - windowH * 0.3
        const end = sectionTop + sectionHeight - windowH * 0.5

        let progress = (scrollY - start) / (end - start)
        progress = Math.min(Math.max(progress, 0), 1)

        cards.forEach((card, index) => {
        const inner = card.querySelector('.item_in')
        const depth = cards.length - 1 - index

        const finalScale = 1 - depth * strength
        const finalY = -depth * moveY
        const finalBrightness = 1 - depth * strength

        const scale = 1 - (1 - finalScale) * progress
        const translateY = finalY * progress
        const brightness = 1 - (1 - finalBrightness) * progress

        inner.style.transform =
            `translateY(${translateY}px) scale(${scale})`
        
        })
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
        requestAnimationFrame(() => {
            update()
            ticking = false
        })
        ticking = true
        }
    })
    update()



        // 파트너사 로고 슬라이더
        $(window).on('load', function () {
            setFlowBanner('.partner_wrap1', '.partner_list1', 60, 'flowRolling');
            setFlowBanner('.partner_wrap2', '.partner_list2', 60, 'flowRolling2');
        });

        function setFlowBanner(wrapSelector, listSelector, speed, animationName) {
        const $wrap = $(wrapSelector);
        let $list = $(listSelector);
        let $clone = $list.clone();
        let wrapWidth = '';
        let listWidth = '';

        $wrap.append($clone);
        initBanner();


        let oldWChk = getDeviceType();
        $(window).on('resize', function () {
            let newWChk = getDeviceType();
            if (newWChk !== oldWChk) {
                oldWChk = newWChk;
                initBanner();
            }
        });


        function initBanner() {
            if (wrapWidth !== '') {
                $wrap.find(listSelector).css('animation', 'none');
                $wrap.find(listSelector).slice(2).remove();
            }
            wrapWidth = $wrap.width();
            listWidth = $list.width();

            if (listWidth < wrapWidth) {
                const listCount = Math.ceil(wrapWidth * 2 / listWidth);
                for (let i = 2; i < listCount; i++) {
                    let $cloned = $clone.clone();
                    $wrap.append($cloned);
                }
            }

            $wrap.find(listSelector).css({
                'animation': `${listWidth / speed}s linear infinite ${animationName}`
            });
        }


        $wrap
            .on('mouseleave', function () {
                $wrap.find(listSelector).css('animation-play-state', 'running');
            });
        }


        function getDeviceType() {
            const w = window.innerWidth;
            if (w > 1501) return 'pc';
            else if (w > 681) return 'ta';
            else return 'mo';
        }
