// postcss.config.mjs (ESM format)
import autoprefixer from 'autoprefixer';

export default {
  plugins: [
    autoprefixer({
      overrideBrowserslist: [
        "last 2 versions",
        "> 1%",
        "iOS >= 12",
        "Safari >= 12",
        "not dead"
      ]
    })
  ]
};