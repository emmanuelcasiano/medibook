import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A plain browser `<select>` with the same look as `Input`. Use it for short lists in forms that post a normal
 * value (the browser's own picker is easy to use on a phone). The Radix `Select` is for custom menus.
 */
function NativeSelect({ className, ...props }: React.ComponentProps<"select">) {
  return (
    <select
      data-slot="native-select"
      className={cn(
        "border-input flex h-11 w-full min-w-0 rounded-md border bg-card px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        "focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
        className
      )}
      {...props}
    />
  )
}

export { NativeSelect }
