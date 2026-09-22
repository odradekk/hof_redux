#!/bin/sh
# 旧版 HOF 本地参照栈入口：源码只读挂载 + 运行时写分离。
# SRC（/srv/old_hof_ro）是仓库 old_hof/ 的只读 bind mount，容器内永不回写；
# DST（/var/www/hof）是命名卷，承载代码副本与全部运行时状态。
set -eu

SRC="${LEGACY_SRC:-/srv/old_hof_ro}"
DST="${APACHE_DOCUMENT_ROOT:-/var/www/hof}"

# 1. 同步代码副本。源目录没有 setting.php / admin.php / *.dat / user/ 等
#    运行时文件（见 old_hof/.gitignore），cp 不删除目标多余文件，
#    因此卷内玩家数据、留言、战报在重启后保留，代码改动则自动刷新。
mkdir -p "$DST"
cp -a "$SRC/." "$DST/"
# 保持 web 根接近真实部署形态，不暴露仓库元信息。
rm -f "$DST/README.md" "$DST/.gitignore" "$DST/.gitattributes"

# 2. dev 凭据：环境变量优先，否则复用卷内持久化值，否则随机生成并持久化。
#    CRYPT_KEY 跨重启必须稳定，否则已注册账号无法登录，故不能每次随机。
#    格式要求（见 old_hof/class/class.user.php:421-423）：
#    CryptPassword 取 substr(crypt(pass, CRYPT_KEY), strlen(CRYPT_KEY))，
#    因此 CRYPT_KEY 必须是 crypt 输出的前缀；32 位裸 hex 会使 crypt 回退到
#    DES（13 字符输出），substr 越界导致所有密码哈希为空、谁也登不上。
#    这里默认生成 $6$（SHA-512crypt）盐，保证注册/登录往返一致、错密码拒绝。
SECRETS_FILE="$DST/.legacy-secrets.env"
if [ ! -f "$SECRETS_FILE" ]; then
    : "${LEGACY_CRYPT_KEY:=$(php -r 'echo "\$6\$" . bin2hex(random_bytes(8)) . "\$";')}"
    : "${LEGACY_UP_PASS:=$(php -r 'echo bin2hex(random_bytes(8));')}"
    : "${LEGACY_ADMIN_PASSWORD:=$(php -r 'echo bin2hex(random_bytes(8));')}"
    {
        echo "LEGACY_SECRET_CRYPT_KEY='$LEGACY_CRYPT_KEY'"
        echo "LEGACY_SECRET_UP_PASS='$LEGACY_UP_PASS'"
        echo "LEGACY_SECRET_ADMIN_PASSWORD='$LEGACY_ADMIN_PASSWORD'"
    } > "$SECRETS_FILE"
    chmod 600 "$SECRETS_FILE"
    echo "legacy dev secrets generated (kept in volume $SECRETS_FILE):"
    echo "  UP_PASS=$LEGACY_UP_PASS ADMIN_PASSWORD=$LEGACY_ADMIN_PASSWORD"
fi
# shellcheck disable=SC1090
. "$SECRETS_FILE"
export LEGACY_SECRET_CRYPT_KEY LEGACY_SECRET_UP_PASS LEGACY_SECRET_ADMIN_PASSWORD
# 允许显式传入的环境变量覆盖卷内旧值（轮换凭据时用）。
if [ -n "${LEGACY_CRYPT_KEY:-}" ]; then export LEGACY_SECRET_CRYPT_KEY="$LEGACY_CRYPT_KEY"; fi
if [ -n "${LEGACY_UP_PASS:-}" ]; then export LEGACY_SECRET_UP_PASS="$LEGACY_UP_PASS"; fi
if [ -n "${LEGACY_ADMIN_PASSWORD:-}" ]; then export LEGACY_SECRET_ADMIN_PASSWORD="$LEGACY_ADMIN_PASSWORD"; fi

# 3. 生成 setting.php / admin.php。缺失时生成；LEGACY_RESET_CONFIG=1 时强制重建。
if [ ! -f "$DST/setting.php" ] || [ "${LEGACY_RESET_CONFIG:-0}" = "1" ]; then
    php /usr/local/bin/gen-legacy-config.php setting "$DST/setting.php"
fi
if [ ! -f "$DST/admin.php" ] || [ "${LEGACY_RESET_CONFIG:-0}" = "1" ]; then
    php /usr/local/bin/gen-legacy-config.php admin "$DST/admin.php"
fi

# 4. 预建运行时可写路径。FileLock 在文件不存在时直接返回 false（静默跳过），
#    因此全部 .dat 必须预先存在；目录缺失则注册/战斗/留言写入失败。
#    路径清单见 old_hof/setting.reference.php:117-142。
mkdir -p "$DST/user" "$DST/union" \
    "$DST/log/normal" "$DST/log/rank" "$DST/log/union"
for f in auction.dat auction_log.dat register.dat update.dat ctrltime.dat \
    ranking.dat bbs.dat bbs_town.dat managed.dat username.dat; do
    if [ ! -e "$DST/$f" ]; then : > "$DST/$f"; fi
done

chown -R www-data:www-data "$DST"

exec "$@"
