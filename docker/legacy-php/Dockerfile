# 旧版 HOF 本地参照栈：php:7.4.33-apache 锁定版。
# PHP 版本选择依据：old_hof 全系使用 PHP4 风格构造器
# （class/class.main.php:10、class/class.user.php:36 等），PHP8 已移除该机制，
# 因此最高只能使用 7.x；7.4.33 是 7.4 最后一个 patch，digest 锁定防漂移。
# 基础镜像实测已自带 mbstring（old_hof/class/global.php:123 需用），此处只补 gd
#（old_hof/image.php 战斗拼图需用）。
FROM php:7.4.33-apache@sha256:c9d7e608f73832673479770d66aacc8100011ec751d1905ff63fae3fe2e0ca6d

ENV APACHE_DOCUMENT_ROOT=/var/www/hof

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
# 本地代理镜像缺 bullseye-security 的 -dev 包（404），故显式 pin 到 bullseye
# main 中可用的对应版本；运行时库同步 pin 以满足 -dev 的精确依赖。
        libpng16-16=1.6.37-3 libpng-dev=1.6.37-3 \
        libjpeg-dev \
        libfreetype6=2.10.4+dfsg-1+deb11u1 \
        libfreetype-dev=2.10.4+dfsg-1+deb11u1 \
        libfreetype6-dev=2.10.4+dfsg-1+deb11u1 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apt-mark manual libpng16-16 libjpeg62-turbo libfreetype6 \
    && apt-get purge -y --auto-remove libpng-dev libjpeg-dev libfreetype6-dev libfreetype-dev \
    && rm -rf /var/lib/apt/lists/* \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY php-legacy.ini /usr/local/etc/php/conf.d/legacy.ini
COPY entrypoint.sh gen-legacy-config.php /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
