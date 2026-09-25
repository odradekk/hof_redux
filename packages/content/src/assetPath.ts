import path from "node:path";

/** 编辑源中的素材路径必须是 content/assets/ 下的规范相对路径。 */
export function resolveAssetPath(contentDir: string, assetPath: string): { absolutePath: string; relativePath: string } {
  const prefix = "content/assets/";
  if (!assetPath.startsWith(prefix)) throw new Error(`素材路径必须位于 ${prefix} 下`);
  const relativePath = assetPath.slice(prefix.length);
  if (relativePath.includes("\\") || relativePath.split("/").some((part) => !part || part === "." || part === "..")) {
    throw new Error(`素材路径不是规范相对路径：${assetPath}`);
  }
  return { absolutePath: path.join(contentDir, "assets", relativePath), relativePath };
}
